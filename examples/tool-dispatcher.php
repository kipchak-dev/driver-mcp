<?php

/**
 * api/Mcp/Dispatcher.php: every tool declares #[SignIn] or #[Anyone], and this dispatcher enforces it. A tool with
 * neither stops the API at boot. Name the class in config/kipchak.mcp.php:
 *
 *     'tool_dispatcher' => \Api\Mcp\Dispatcher::class,
 */

namespace Api\Mcp;

use Kipchak\Driver\Mcp\ToolCall;
use Kipchak\Driver\Mcp\ToolDefinition;
use Kipchak\Driver\Mcp\ToolDispatcher;
use Mcp\Server\Exception\ToolCallException;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Anyone
{
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class SignIn
{
}

final class Dispatcher implements ToolDispatcher
{
    public function accept(ToolDefinition $tool): void
    {
        if (self::needsSignIn($tool) === null) {
            throw new \LogicException("MCP tool '{$tool->name}' declares neither #[SignIn] nor #[Anyone].");
        }
    }

    public function dispatch(ToolCall $call, \Closure $next): mixed
    {
        if (self::needsSignIn($call->tool) && $call->caller === null) {
            throw new ToolCallException('Sign in to use this tool.');
        }

        // The tool reads this with Grant::from($context).
        return $next(['subject' => $call->caller['sub'] ?? null]);
    }

    private static function needsSignIn(ToolDefinition $tool): ?bool
    {
        $signIn = $tool->function?->getAttributes(SignIn::class) ?? [];
        $anyone = $tool->function?->getAttributes(Anyone::class) ?? [];

        return match (true) {
            $signIn !== [] && $anyone === [] => true,
            $anyone !== [] && $signIn === [] => false,
            default => null,
        };
    }
}
