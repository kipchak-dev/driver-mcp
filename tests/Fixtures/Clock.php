<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

final class Clock
{
    public function now(): string
    {
        return '2026-11-15T00:00:00Z';
    }
}
