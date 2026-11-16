<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

use Kipchak\Driver\Mcp\ToolCall;
use Kipchak\Driver\Mcp\ToolDefinition;
use Kipchak\Driver\Mcp\ToolDispatcher;
use Mcp\Server\Exception\ToolCallException;

/**
 * A dispatcher for the tests. It refuses a tool without #[Level]; runs 'public' tools; refuses 'member' tools to an
 * anonymous caller and grants a signed-in one their subject; and misbehaves on purpose for the levels 'fault',
 * 'twice' and 'answers'.
 */
final class FixtureDispatcher implements ToolDispatcher
{
    public function __construct(private readonly DispatchLog $log)
    {
    }

    public function accept(ToolDefinition $tool): void
    {
        if (self::level($tool) === null) {
            throw new \LogicException("Tool '{$tool->name}' declares no level.");
        }
        $this->log->accepted[] = $tool->name;
    }

    public function dispatch(ToolCall $call, \Closure $next): mixed
    {
        $this->log->calls[] = $call;

        return match (self::level($call->tool)) {
            'public' => $next(),
            'member' => $call->caller === null
                ? throw new ToolCallException('Sign in to use this tool.')
                : $next(['subject' => $call->caller['sub']]),
            'fault' => throw new \RuntimeException('internal detail that must not reach the caller'),
            'twice' => [$next(), $next()],
            'answers' => 'answered by the dispatcher',
            default => throw new \LogicException('unknown level'),
        };
    }

    private static function level(ToolDefinition $tool): ?string
    {
        $attributes = $tool->function?->getAttributes(Level::class) ?? [];

        return count($attributes) === 1 ? $attributes[0]->newInstance()->name : null;
    }
}
