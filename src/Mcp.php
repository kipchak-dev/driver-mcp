<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp;

use Kipchak\Core\Interfaces;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Mcp\Exception\ConfigurationException;
use Kipchak\Driver\Mcp\Library\ServerFactory;
use Kipchak\Driver\Mcp\Library\ServerSettings;
use Mcp\Server\Server;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Serves Model Context Protocol tools, resources and prompts from a Kipchak API, over HTTP, with the official
 * mcp/sdk. Each server in config/kipchak.mcp.php is registered as drivers.mcp.<name>; route it with
 *
 *     $app->any('/mcp', Mcp::endpoint('default'));
 *
 * Every server's settings are checked when the driver initialises, so a configuration error stops the API at boot.
 */
class Mcp implements Interfaces\Driver
{
    protected static ?ContainerInterface $container = null;

    /** @var array<string, ServerSettings> */
    protected static array $settings = [];

    public static function initialise(ContainerInterface $container, ?string $configFileName = null): void
    {
        self::$container = $container;
        self::set($configFileName);
    }

    public static function set(?string $configFileName = null): void
    {
        $configFileName = $configFileName ?? 'kipchak.mcp';
        $config = Config::get($configFileName);
        self::$settings = [];

        $servers = $config['servers'] ?? [];
        if (!is_array($servers)) {
            throw new ConfigurationException("kipchak.mcp: 'servers' must be a map of server names to settings.");
        }

        foreach ($servers as $name => $serverConfig) {
            $name = (string) $name;
            if (!is_array($serverConfig)) {
                throw ConfigurationException::for($name, 'servers', 'must map the server name to an array of settings.');
            }
            $settings = ServerSettings::fromConfig($name, $serverConfig, self::projectRoot());
            ServerFactory::checkTools($settings, self::logger(self::$container), self::$container);
            self::$settings[$name] = $settings;

            self::$container->set(
                'drivers.mcp.' . $name,
                static fn (ContainerInterface $c): Server => ServerFactory::build($settings, $c, self::logger($c)),
            );
        }
    }

    public static function get(?string $configKey = null): Server
    {
        return self::$container->get('drivers.mcp.' . ($configKey ?? 'default'));
    }

    /**
     * The route handler for one server. The server itself is built on the first request and kept for the life of the
     * worker; each request runs it with its own transport.
     */
    public static function endpoint(string $server = 'default'): Endpoint
    {
        if (!isset(self::$settings[$server])) {
            throw new ConfigurationException("kipchak.mcp has no server '{$server}'. Servers configured: " . (self::$settings === [] ? 'none' : implode(', ', array_keys(self::$settings))) . '.');
        }
        $container = self::$container;

        return new Endpoint(
            self::$settings[$server],
            static fn (): Server => $container->get('drivers.mcp.' . $server),
            new ResponseFactory(),
            new StreamFactory(),
            self::logger($container),
        );
    }

    private static function logger(ContainerInterface $container): LoggerInterface
    {
        if ($container->has('drivers.logger')) {
            $logger = $container->get('drivers.logger');
            if ($logger instanceof LoggerInterface) {
                return $logger;
            }
        }

        return new NullLogger();
    }

    /** The project root, as Kipchak core finds config/ and routes/: four levels up from vendor/kipchak/<package>/src. */
    private static function projectRoot(): string
    {
        return (string) (realpath(__DIR__ . '/../../../../') ?: getcwd());
    }
}
