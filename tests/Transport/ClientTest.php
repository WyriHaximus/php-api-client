<?php
declare(strict_types=1);

namespace WyriHaximus\Tests\AppVeyor\Transport;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Phake;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use React\Cache\CacheInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\RejectedPromise;
use WyriHaximus\ApiClient\Transport\Client;
use WyriHaximus\ApiClient\Transport\Hydrator;
use function Clue\React\Block\await;
use function React\Promise\resolve;

class ClientTest extends \PHPUnit_Framework_TestCase
{
    public function testGetLoop()
    {
        $client = new Client(
            Factory::create(),
            Phake::mock(GuzzleClient::class)
        );
        $this->assertInstanceOf(LoopInterface::class, $client->getLoop());
    }

    public function testGetHydrator()
    {
        $client = new Client(
            Phake::mock(LoopInterface::class),
            Phake::mock(GuzzleClient::class)
        );
        $this->assertInstanceOf(Hydrator::class, $client->getHydrator());
    }

    public function testRequest()
    {
        $loop = Factory::create();

        $stream = Phake::mock(StreamInterface::class);
        Phake::when($stream)->getContents()->thenReturn('{"foo":"bar"}');

        $response = Phake::mock(ResponseInterface::class);
        Phake::when($response)->getBody()->thenReturn($stream);

        $request = false;
        $handler = Phake::mock(GuzzleClient::class);
        Phake::when($handler)->sendAsync($this->isInstanceOf(Request::class))->thenReturnCallback(function (RequestInterface $guzzleRequest) use ($response, &$request) {
            $request = $guzzleRequest;
            return new FulfilledPromise($response);
        });

        $client = new Client(
            $loop,
            $handler,
            [
                'host' => 'api.example.com',
            ]
        );

        $client->request('status', true);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api.example.com/status', (string) $request->getUri());
        $this->assertSame([
            'Host' => ['api.example.com'],
            'User-Agent' => ['WyriHaximus/php-api-client'],
        ], $request->getHeaders());
    }

    public function testRequestRefreshHitAPI()
    {
        $loop = Factory::create();

        $cache = Phake::mock(CacheInterface::class);

        $stream = Phake::mock(StreamInterface::class);
        Phake::when($stream)->getContents()->thenReturn('{"foo":"bar"}');

        $response = Phake::mock(Response::class);
        Phake::when($response)->getBody()->thenReturn($stream);

        $handler = Phake::mock(GuzzleClient::class);
        Phake::when($handler)->sendAsync($this->isInstanceOf(Request::class))->thenReturn(resolve($response));

        $client = new Client(
            $loop,
            $handler,
            [
                'cache' => $cache,
                'host' => 'api.example.com',
            ]
        );

        $client->request('status', true);
        $loop->run();

        Phake::verify($handler)->sendAsync($this->isInstanceOf(Request::class));
        Phake::verify($cache, Phake::never())->get('status');
        Phake::verify($cache)->set('status', '{"foo":"bar"}');
    }

    public function testRequestNoCacheHitAPI()
    {
        $loop = Factory::create();

        $stream = Phake::mock(StreamInterface::class);
        Phake::when($stream)->getContents()->thenReturn('{"foo":"bar"}');

        $response = Phake::mock(Response::class);
        Phake::when($response)->getBody()->thenReturn($stream);

        $handler = Phake::mock(GuzzleClient::class);
        Phake::when($handler)->sendAsync($this->isInstanceOf(Request::class))->thenReturn(new FulfilledPromise($response));

        $client = new Client(
            $loop,
            $handler,
            [
                'host' => 'api.example.com',
            ]
        );

        $client->request('status');
        $loop->run();

        Phake::verify($handler)->sendAsync($this->isInstanceOf(Request::class));
    }

    public function testRequestCacheMissHitAPI()
    {
        $loop = Factory::create();

        $cache = Phake::mock(CacheInterface::class);
        Phake::when($cache)->get('status')->thenReturn(new RejectedPromise());

        $stream = Phake::mock(StreamInterface::class);
        Phake::when($stream)->getContents()->thenReturn('{"foo":"bar"}');

        $response = Phake::mock(Response::class);
        Phake::when($response)->getBody()->thenReturn($stream);

        $handler = Phake::mock(GuzzleClient::class);
        Phake::when($handler)->sendAsync($this->isInstanceOf(Request::class))->thenReturn(resolve($response));

        $client = new Client(
            $loop,
            $handler,
            [
                'cache' => $cache,
                'host' => 'api.example.com',
            ]
        );

        $result = await($client->request('status'), $loop, 3);
        $this->assertSame([
            'foo' => 'bar',
        ], $result);

        Phake::inOrder(
            Phake::verify($cache)->get('status'),
            Phake::verify($handler)->sendAsync($this->isInstanceOf(RequestInterface::class)),
            Phake::verify($cache)->set('status', '{"foo":"bar"}')
        );
    }

    public function testRequestCacheHitIgnoreAPI()
    {
        $loop = Factory::create();

        $cache = Phake::mock(CacheInterface::class);
        Phake::when($cache)->get('status')->thenReturn(resolve('{"foo":"bar"}'));

        $handler = Phake::mock(GuzzleClient::class);

        $client = new Client(
            $loop,
            $handler,
            [
                'cache' => $cache,
                'host' => 'api.example.com',
            ]
        );

        $result = await($client->request('status'), $loop, 3);
        $this->assertSame([
            'foo' => 'bar',
        ], $result);

        Phake::verify($cache)->get('status');
        Phake::verify($handler, Phake::never())->sendAsync($this->isInstanceOf(RequestInterface::class));
    }

    public function provideGetBaseURL()
    {
        yield [
            [
                'schema' => 'http',
                'host' => 'api.wyrihaximus.net',
            ],
            'http://api.wyrihaximus.net/'
        ];

        yield [
            [
                'host' => 'wyrihaximus.net',
                'path' => '/api/',
            ],
            'https://wyrihaximus.net/api/'
        ];

        yield [
            [
                'schema' => 'gopher',
                'host' => 'thorerik.com',
            ],
            'gopher://thorerik.com/'
        ];
    }

    /**
     * @dataProvider provideGetBaseURL
     */
    public function testGetBaseURL(array $options, string $baseURL)
    {
        $loop = Factory::create();
        $handler = Phake::mock(GuzzleClient::class);

        $client = new Client(
            $loop,
            $handler,
            $options
        );

        $this->assertSame($baseURL, $client->getBaseURL());
    }
}
