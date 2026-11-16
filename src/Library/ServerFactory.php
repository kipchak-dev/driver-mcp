<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Library;

use Kipchak\Driver\Mcp\Exception\ConfigurationException;
use Mcp\Server\Capability\Discovery\Discoverer;
use Kipchak\Driver\Mcp\ToolDefinition;
use Kipchak\Driver\Mcp\ToolDispatcher;
use Mcp\Server\Capability\Registry;
use Mcp\Server\Capability\Registry\Loader\DiscoveryLoader;
use Mcp\Server\Capability\Registry\ReferenceHandler;
use Mcp\Server\Capability\RegistryInterface;
use Mcp\Server\Server;
use Mcp\Server\Stateless\OriginatingRequestBinder;
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
        // Said when the server is built, on the first MCP request, not at boot: at boot the Kipchak logger writes to
        // standard output, where the Kipchak CLI reads the project's JSON.
        if ($settings->offersNothing()) {
            $logger->warning("MCP server '{$settings->server}' in kipchak.mcp lists no class in 'tools' and no directory in 'discovery', so it offers an assistant nothing.");
        }

        $builder = Server::builder()
            ->setServerInfo($settings->name, $settings->version)
            ->setInstructions($settings->instructions)
            ->setContainer($container)
            // The SDK logs several info lines for every request; only its warnings and worse reach the API log.
            ->setLogger(new WarningsOnly($logger))
            // Load the tools when the server is built, once per worker, rather than on the first registry read in
            // the middle of a request.
            ->setLazyLoading(false);

        if ($settings->tools !== []) {
            $builder->addLoader(new ClassLoader($settings->server, $settings->tools, new Discoverer(new WarningsOnly($logger))));
        }

        if ($settings->discovery !== []) {
            $builder->setDiscovery($settings->basePath, $settings->discovery);
        }

        if ($settings->requestStateKeys !== []) {
            // Binds each state to the request it was issued for: its method, tool or prompt, and arguments.
            $builder->setRequestState($settings->requestStateKeys, $settings->requestStateTtl, new OriginatingRequestBinder());
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

        if ($settings->toolDispatcher !== null) {
            $dispatcher = self::dispatcher($settings, $container);
            $builder->setReferenceHandler(new DispatchingReferenceHandler(new ReferenceHandler($container), $dispatcher));
            // The driver's own registry, which the SDK loads eagerly at build, so that every tool the server will
            // serve, discovered ones included, is put to the dispatcher before the server is used. A refusal here
            // fails the request that built the server, and every later one.
            $registry = new Registry(logger: new WarningsOnly($logger));
            $builder->setRegistry($registry);
            $server = $builder->build();
            self::acceptAll($registry, $dispatcher);

            return $server;
        }

        return $builder->build();
    }

    public static function dispatcher(ServerSettings $settings, ContainerInterface $container): ToolDispatcher
    {
        $dispatcher = $container->get((string) $settings->toolDispatcher);
        if (!$dispatcher instanceof ToolDispatcher) {
            throw ConfigurationException::for($settings->server, 'tool_dispatcher', 'names a class the container did not build as a ' . ToolDispatcher::class . '.');
        }

        return $dispatcher;
    }

    public static function acceptAll(RegistryInterface $registry, ToolDispatcher $dispatcher): void
    {
        foreach (array_keys($registry->getTools()->references) as $name) {
            $dispatcher->accept(ToolDefinition::fromReference($registry->getTool((string) $name)));
        }
    }

    /**
     * Reads the attributes of the classes in 'tools' at boot, so that a class with no MCP attributes, or two classes
     * declaring one name, stop the API when it starts rather than on its first MCP request.
     *
     * With a tool dispatcher, the discovery directories are scanned at boot as well, and every tool is put to the
     * dispatcher, so that a tool it refuses stops the API when it starts.
     */
    public static function checkTools(ServerSettings $settings, LoggerInterface $logger, ?ContainerInterface $container = null): void
    {
        $governed = $settings->toolDispatcher !== null && $container !== null;
        if ($settings->tools === [] && !$governed) {
            return;
        }

        $registry = new Registry(logger: new WarningsOnly($logger));
        if ($settings->tools !== []) {
            (new ClassLoader($settings->server, $settings->tools, new Discoverer(new WarningsOnly($logger))))->load($registry);
        }

        if ($governed) {
            if ($settings->discovery !== []) {
                (new DiscoveryLoader($settings->basePath, $settings->discovery, [], new Discoverer(new WarningsOnly($logger))))->load($registry);
            }
            self::acceptAll($registry, self::dispatcher($settings, $container));
        }
    }
}
