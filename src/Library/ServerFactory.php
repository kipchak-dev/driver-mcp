<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Library;

use Kipchak\Driver\Mcp\Exception\ConfigurationException;
use Mcp\Server\Capability\Discovery\Discoverer;
use Mcp\Server\Capability\Registry;
use Mcp\Server\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds one MCP server from its settings. The driver calls this once per worker and keeps the result: the server holds
 * the registry and the dispatchers, and every request runs it with a transport of its own (see Endpoint), which is
 * the pattern the SDK uses in its own long-running examples.
 */
final class ServerFactory
{
    public static function build(ServerSettings $settings, ContainerInterface $container, LoggerInterface $logger): Server
    {
        $builder = Server::builder()
            ->setServerInfo($settings->name, $settings->version)
            ->setInstructions($settings->instructions)
            ->setContainer($container)
            ->setLogger($logger)
            // Load the tools when the server is built, once per worker, rather than on the first registry read in
            // the middle of a request.
            ->setLazyLoading(false);

        if ($settings->tools !== []) {
            $builder->addLoader(new ClassLoader($settings->server, $settings->tools, new Discoverer($logger)));
        }

        if ($settings->discovery !== []) {
            $builder->setDiscovery($settings->basePath, $settings->discovery);
        }

        if ($settings->sessions !== null) {
            if (!$container->has($settings->sessions)) {
                throw ConfigurationException::for($settings->server, 'sessions', "names '{$settings->sessions}', which is not in the container. Initialise that cache driver before Mcp.");
            }
            $cache = $container->get($settings->sessions);
            if (!$cache instanceof CacheInterface) {
                throw ConfigurationException::for($settings->server, 'sessions', "names '{$settings->sessions}', which is a " . get_debug_type($cache) . ', not a PSR-16 cache (Psr\SimpleCache\CacheInterface).');
            }
            $builder->setSession(new Psr16SessionStore($cache, 'mcp-' . $settings->server . '-', $settings->sessionTtl));
        }

        return $builder->build();
    }

    /**
     * Reads the attributes of the classes in 'tools' at boot, without the container, so that a class with no MCP
     * attributes, or two classes declaring one name, stop the API when it starts rather than on its first MCP request.
     */
    public static function checkTools(ServerSettings $settings, LoggerInterface $logger): void
    {
        if ($settings->tools === []) {
            return;
        }

        (new ClassLoader($settings->server, $settings->tools, new Discoverer($logger)))->load(new Registry(logger: $logger));
    }
}
