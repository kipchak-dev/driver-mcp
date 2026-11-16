<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp;

/**
 * Decides, in one place, whether and how each tool call runs. Name the class in a server's 'tool_dispatcher' setting
 * in config/kipchak.mcp.php; the Kipchak container builds it.
 *
 * accept() is called for every tool of the server: at boot for the classes listed in 'tools', and when the server is
 * built for every tool, including discovered ones. Throwing there stops the API, so a tool the dispatcher cannot
 * govern is never served.
 *
 * dispatch() runs every call. It calls $next() to run the tool, at most once, and returns its result; or it returns a
 * result of its own without calling it. An exception that is not an Mcp\Server\Exception\ToolCallException becomes the SDK's
 * internal error, which carries no data, so a dispatcher that fails closes the call.
 */
interface ToolDispatcher
{
    /**
     * @throws \Throwable to refuse the tool, which stops the API
     */
    public function accept(ToolDefinition $tool): void;

    /**
     * @param \Closure(): mixed $next runs the tool
     */
    public function dispatch(ToolCall $call, \Closure $next): mixed;
}
