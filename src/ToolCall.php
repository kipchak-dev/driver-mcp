<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp;

/**
 * One tool call, as the dispatcher receives it.
 *
 * $caller holds the claims the endpoint passed to the SDK for this request (issuer, client and subject, as 'iss',
 * 'client_id' and 'sub'), or null for an anonymous call. It comes from this request alone.
 */
final readonly class ToolCall
{
    /**
     * @param array<string, mixed>       $arguments the caller's arguments, without the SDK's own entries
     * @param array<string, string>|null $caller
     */
    public function __construct(
        public ToolDefinition $tool,
        public array $arguments,
        public ?array $caller,
    ) {
    }
}
