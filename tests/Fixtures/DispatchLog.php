<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

use Kipchak\Driver\Mcp\ToolCall;

/** What FixtureDispatcher saw, for the tests to read. */
final class DispatchLog
{
    /** @var list<string> */
    public array $accepted = [];

    /** @var list<ToolCall> */
    public array $calls = [];

    public int $toolRuns = 0;
}
