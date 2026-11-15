<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fake;

use DI\Container;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Mcp\Mcp;
use Kipchak\Driver\Mcp\Tests\Fixtures\StatusTools;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

trait BootsDriver
{
    protected RecordingLogger $log;

    /**
     * @param array<string, mixed> $overrides
     */
    protected function boot(array $overrides = [], ?Container $container = null): Container
    {
        $container ??= new Container();
        $this->log = new RecordingLogger();
        $container->set('drivers.logger', $this->log);
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.mcp' => ['servers' => ['default' => array_replace(self::baseServer(), $overrides)]]]);
        Mcp::initialise($container);

        return $container;
    }

    /** @return array<string, mixed> */
    protected static function baseServer(): array
    {
        return [
            'name' => 'Test API',
            'version' => '1.0.0',
            'tools' => [StatusTools::class],
            'allowed_hosts' => ['localhost', 'api.example.test'],
        ];
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    protected static function request(array|string $message, array $headers = [], string $host = 'localhost', string $method = 'POST'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, "http://{$host}/mcp")
            ->withHeader('Host', $host)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request->withBody((new StreamFactory())->createStream(is_string($message) ? $message : json_encode($message, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    protected static function initialize(int $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ]];
    }

    /**
     * A 2026-07-28 request: no initialize, everything the server needs travels in _meta.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected static function modern(int $id, string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ];

        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }

    /** @return array<string, mixed> */
    protected static function json(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if (str_starts_with($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
            preg_match_all('/^data: (.*)$/m', $body, $m);
            $body = (string) end($m[1]);
        }

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }
}
