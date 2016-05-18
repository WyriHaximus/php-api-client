<?php
declare(strict_types=1);

namespace WyriHaximus\Tests\AppVeyor\Transport;

use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use WyriHaximus\ApiClient\Transport\Client;
use WyriHaximus\ApiClient\Transport\Factory;

class FactoryTest extends \PHPUnit_Framework_TestCase
{
    public function testCreate()
    {
        $loop = LoopFactory::create();
        $client = Factory::create($loop);
        $this->assertInstanceOf(Client::class, $client);
        $this->assertInstanceOf(LoopInterface::class, $client->getLoop());
        $this->assertSame($loop, $client->getLoop());
    }

    public function testCreateWithoutLoop()
    {
        $client = Factory::create();
        $this->assertInstanceOf(Client::class, $client);
        $this->assertInstanceOf(LoopInterface::class, $client->getLoop());
    }
}
