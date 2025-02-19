<?php

namespace React\Tests\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Io\HttpBodyStream;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Stream\ThroughStream;
use React\Tests\Http\TestCase;
use RingCentral\Psr7\BufferStream;

final class RequestBodyBufferMiddlewareTest extends TestCase
{
    public function testBufferingResolvesWhenStreamEnds()
    {
        $stream = new ThroughStream();
        $serverRequest = new ServerRequest(
            'GET',
            'https://example.com/',
            array(),
            new HttpBodyStream($stream, 11)
        );

        $exposedRequest = null;
        $buffer = new RequestBodyBufferMiddleware(20);
        $buffer(
            $serverRequest,
            function (ServerRequestInterface $request) use (&$exposedRequest) {
                $exposedRequest = $request;
            }
        );

        $stream->write('hello');
        $stream->write('world');
        $stream->end('!');

        $this->assertSame(11, $exposedRequest->getBody()->getSize());
        $this->assertSame('helloworld!', $exposedRequest->getBody()->getContents());
    }

    public function testAlreadyBufferedResolvesImmediately()
    {
        $size = 1024;
        $body = str_repeat('x', $size);
        $stream = new BufferStream(1024);
        $stream->write($body);
        $serverRequest = new ServerRequest(
            'GET',
            'https://example.com/',
            array(),
            $stream
        );

        $exposedRequest = null;
        $buffer = new RequestBodyBufferMiddleware();
        $buffer(
            $serverRequest,
            function (ServerRequestInterface $request) use (&$exposedRequest) {
                $exposedRequest = $request;
            }
        );

        $this->assertSame($size, $exposedRequest->getBody()->getSize());
        $this->assertSame($body, $exposedRequest->getBody()->getContents());
    }

    public function testEmptyStreamingResolvesImmediatelyWithEmptyBufferedBody()
    {
        $stream = new ThroughStream();
        $serverRequest = new ServerRequest(
            'GET',
            'https://example.com/',
            array(),
            $body = new HttpBodyStream($stream, 0)
        );

        $exposedRequest = null;
        $buffer = new RequestBodyBufferMiddleware();
        $buffer(
            $serverRequest,
            function (ServerRequestInterface $request) use (&$exposedRequest) {
                $exposedRequest = $request;
            }
        );

        $this->assertSame(0, $exposedRequest->getBody()->getSize());
        $this->assertSame('', $exposedRequest->getBody()->getContents());
        $this->assertNotSame($body, $exposedRequest->getBody());
    }

    public function testEmptyBufferedResolvesImmediatelyWithSameBody()
    {
        $serverRequest = new ServerRequest(
            'GET',
            'https://example.com/',
            array(),
            ''
        );
        $body = $serverRequest->getBody();

        $exposedRequest = null;
        $buffer = new RequestBodyBufferMiddleware();
        $buffer(
            $serverRequest,
            function (ServerRequestInterface $request) use (&$exposedRequest) {
                $exposedRequest = $request;
            }
        );

        $this->assertSame(0, $exposedRequest->getBody()->getSize());
        $this->assertSame('', $exposedRequest->getBody()->getContents());
        $this->assertSame($body, $exposedRequest->getBody());
    }

    public function testKnownExcessiveSizedBodyIsDisgardedTheRequestIsPassedDownToTheNextMiddleware()
    {
        $stream = new ThroughStream();
        $stream->end('aa');
        $serverRequest = new ServerRequest(
            'GET',
            'https://example.com/',
            array(),
            new HttpBodyStream($stream, 2)
        );

        $buffer = new RequestBodyBufferMiddleware(1);
        $response = \React\Async\await($buffer(
            $serverRequest,
            function (ServerRequestInterface $request) {
                return new Response(200, array(), $request->getBody()->getContents());
            }
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getBody()->getContents());
    }

    public function testKnownExcessiveSizedWithIniLikeSize()
    {
        $stream = new ThroughStream();
        Loop::addTimer(0.001, function () use ($stream) {
            $stream->end(str_repeat('a', 2048));
        });
        $serverRequest = new ServerRequest(
            'GET',
            'https://example.com/',
            array(),
            new HttpBodyStream($stream, 2048)
        );

        $buffer = new RequestBodyBufferMiddleware('1K');
        $response = \React\Async\await($buffer(
            $serverRequest,
            function (ServerRequestInterface $request) {
                return new Response(200, array(), $request->getBody()->getContents());
            }
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getBody()->getContents());
    }

    public function testAlreadyBufferedExceedingSizeResolvesImmediatelyWithEmptyBody()
    {
        $serverRequest = new ServerRequest(
            'GET',
            'https://example.com/',
            array(),
            'hello'
        );

        $exposedRequest = null;
        $buffer = new RequestBodyBufferMiddleware(1);
        $buffer(
            $serverRequest,
            function (ServerRequestInterface $request) use (&$exposedRequest) {
                $exposedRequest = $request;
            }
        );

        $this->assertSame(0, $exposedRequest->getBody()->getSize());
        $this->assertSame('', $exposedRequest->getBody()->getContents());
    }

    public function testExcessiveSizeBodyIsDiscardedAndTheRequestIsPassedDownToTheNextMiddleware()
    {
        $stream = new ThroughStream();
        $serverRequest = new ServerRequest(
            'GET',
            'https://example.com/',
            array(),
            new HttpBodyStream($stream, null)
        );

        $buffer = new RequestBodyBufferMiddleware(1);
        $promise = $buffer(
            $serverRequest,
            function (ServerRequestInterface $request) {
                return new Response(200, array(), $request->getBody()->getContents());
            }
        );

        $stream->end('aa');

        $exposedResponse = \React\Async\await($promise->then(
            null,
            $this->expectCallableNever()
        ));

        $this->assertSame(200, $exposedResponse->getStatusCode());
        $this->assertSame('', $exposedResponse->getBody()->getContents());
    }

    public function testBufferingErrorThrows()
    {
        $stream = new ThroughStream();
        $serverRequest = new ServerRequest(
            'GET',
            'https://example.com/',
            array(),
            new HttpBodyStream($stream, null)
        );

        $buffer = new RequestBodyBufferMiddleware(1);
        $promise = $buffer(
            $serverRequest,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $stream->emit('error', array(new \RuntimeException()));

        $this->setExpectedException('RuntimeException');
        \React\Async\await($promise);
    }

    public function testFullBodyStreamedBeforeCallingNextMiddleware()
    {
        $promiseResolved = false;
        $middleware = new RequestBodyBufferMiddleware(3);
        $stream = new ThroughStream();
        $serverRequest = new ServerRequest(
            'GET',
            'https://example.com/',
            array(),
            new HttpBodyStream($stream, null)
        );

        $middleware($serverRequest, function () {
            return new Response();
        })->then(function () use (&$promiseResolved) {
            $promiseResolved = true;
        });

        $stream->write('aaa');
        $this->assertFalse($promiseResolved);
        $stream->write('aaa');
        $this->assertFalse($promiseResolved);
        $stream->end('aaa');
        $this->assertTrue($promiseResolved);
    }
}
