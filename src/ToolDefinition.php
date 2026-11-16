<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp;

use Mcp\Server\Capability\Registry\ToolReference;

/**
 * One tool as the dispatcher sees it: its name and the PHP method or function that implements it, so that the
 * dispatcher can read the attributes declared on it.
 */
final readonly class ToolDefinition
{
    public function __construct(
        public string $name,
        public ?\ReflectionFunctionAbstract $function,
    ) {
    }

    public static function fromReference(ToolReference $reference): self
    {
        $handler = $reference->handler;
        $function = null;
        try {
            $function = match (true) {
                $handler instanceof \Closure => new \ReflectionFunction($handler),
                is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[1]) => new \ReflectionMethod($handler[0], $handler[1]),
                is_string($handler) && str_contains($handler, '::') => new \ReflectionMethod($handler),
                is_string($handler) && class_exists($handler) && method_exists($handler, '__invoke') => new \ReflectionMethod($handler, '__invoke'),
                default => null,
            };
        } catch (\ReflectionException) {
            $function = null;
        }

        return new self($reference->tool->name, $function);
    }
}
