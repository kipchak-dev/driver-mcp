<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Exception;

use InvalidArgumentException;

/**
 * A server in config/kipchak.mcp.php cannot be built. The message names the server and the key, so the API stops at
 * boot with something a person can act on rather than failing on the first MCP request.
 */
final class ConfigurationException extends InvalidArgumentException
{
    public static function for(string $server, string $key, string $problem): self
    {
        return new self("MCP server '{$server}' in kipchak.mcp: '{$key}' {$problem}");
    }
}
