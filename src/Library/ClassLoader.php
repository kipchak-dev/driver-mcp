<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Library;

use Kipchak\Driver\Mcp\Exception\ConfigurationException;
use Mcp\Server\Capability\Discovery\DiscovererInterface;
use Mcp\Server\Capability\Registry\Loader\LoaderInterface;
use Mcp\Server\Capability\RegistryInterface;
use ReflectionClass;

/**
 * Registers the tools, resources, resource templates and prompts declared with attributes on the classes listed in a
 * server's 'tools' setting.
 *
 * The SDK's own Discoverer reads each class's file, so an attribute means exactly what it means under the SDK's
 * directory discovery (descriptions from doc blocks, schemas from types, completion providers, invokable classes).
 * Only elements whose handler is the listed class are kept: the scan of the class's directory could otherwise pick up
 * a file of the same name in a subdirectory. A name that two classes both declare stops the server from being built,
 * rather than one silently replacing the other.
 */
final readonly class ClassLoader implements LoaderInterface
{
    /**
     * @param list<class-string> $classes
     */
    public function __construct(
        private string $server,
        private array $classes,
        private DiscovererInterface $discoverer,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        $seen = [];
        foreach ($this->classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if ($file === false) {
                throw ConfigurationException::for($this->server, 'tools', "names {$class}, which has no source file to read attributes from.");
            }

            $state = $this->discoverer->discover(dirname($file), ['.'], [], [basename($file)]);
            $count = 0;

            foreach ($state->getTools() as $name => $reference) {
                if (self::handlerClass($reference->handler) !== $class) {
                    continue;
                }
                self::claim($seen, "tool '{$name}'", $class);
                $registry->registerTool($reference->tool, $reference->handler);
                $count++;
            }
            foreach ($state->getResources() as $uri => $reference) {
                if (self::handlerClass($reference->handler) !== $class) {
                    continue;
                }
                self::claim($seen, "resource '{$uri}'", $class);
                $registry->registerResource($reference->resource, $reference->handler);
                $count++;
            }
            foreach ($state->getResourceTemplates() as $uriTemplate => $reference) {
                if (self::handlerClass($reference->handler) !== $class) {
                    continue;
                }
                self::claim($seen, "resource template '{$uriTemplate}'", $class);
                $registry->registerResourceTemplate($reference->resourceTemplate, $reference->handler, $reference->completionProviders);
                $count++;
            }
            foreach ($state->getPrompts() as $name => $reference) {
                if (self::handlerClass($reference->handler) !== $class) {
                    continue;
                }
                self::claim($seen, "prompt '{$name}'", $class);
                $registry->registerPrompt($reference->prompt, $reference->handler, $reference->completionProviders);
                $count++;
            }

            if ($count === 0) {
                throw ConfigurationException::for($this->server, 'tools', "names {$class}, which declares no #[McpTool], #[McpResource], #[McpResourceTemplate] or #[McpPrompt] on a public method or on the class.");
            }
        }
    }

    /**
     * @param array<string, string> $seen
     */
    private function claim(array &$seen, string $element, string $class): void
    {
        if (isset($seen[$element])) {
            throw ConfigurationException::for($this->server, 'tools', "declares the {$element} in both {$seen[$element]} and {$class}. Give one of them another name.");
        }
        $seen[$element] = $class;
    }

    private static function handlerClass(mixed $handler): ?string
    {
        return match (true) {
            is_array($handler) && is_string($handler[0] ?? null) => ltrim($handler[0], '\\'),
            is_string($handler) => ltrim(explode('::', $handler, 2)[0], '\\'),
            default => null,
        };
    }
}
