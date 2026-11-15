<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

/**
 * Needs a value no autowiring can supply, so a tool that receives one proves it came from the Kipchak container.
 */
final readonly class Clock
{
    public function __construct(private string $now)
    {
    }

    public function now(): string
    {
        return $this->now;
    }
}
