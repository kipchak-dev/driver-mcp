<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

/** The access a fixture tool declares, read by FixtureDispatcher. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Level
{
    public function __construct(public string $name)
    {
    }
}
