<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

use Mcp\Server\Capability\Attribute\McpResource;
use Mcp\Server\Capability\Attribute\McpTool;

/**
 * Tools for the tests. The Clock dependency proves that handlers come from the Kipchak container.
 */
final class StatusTools
{
    public function __construct(private Clock $clock)
    {
    }

    /**
     * Reports the API's status.
     */
    #[McpTool(name: 'status')]
    public function status(): array
    {
        return ['status' => 'ok', 'time' => $this->clock->now()];
    }

    /**
     * Adds two whole numbers.
     */
    #[McpTool(name: 'add')]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    #[McpResource(uri: 'status://api', name: 'api-status')]
    public function resource(): array
    {
        return ['status' => 'ok'];
    }
}
