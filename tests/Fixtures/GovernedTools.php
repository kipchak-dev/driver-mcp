<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

use Kipchak\Driver\Mcp\Grant;
use Mcp\Server\Capability\Attribute\McpResource;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

final class GovernedTools
{
    public function __construct(private readonly DispatchLog $log)
    {
    }

    #[McpTool(name: 'echo')]
    #[Level('public')]
    public function echo(string $value): string
    {
        $this->log->toolRuns++;

        return $value;
    }

    #[McpTool(name: 'mine')]
    #[Level('member')]
    public function mine(RequestContext $context): string
    {
        $this->log->toolRuns++;

        return 'grant=' . json_encode(Grant::from($context));
    }

    #[McpTool(name: 'fault')]
    #[Level('fault')]
    public function fault(): string
    {
        $this->log->toolRuns++;

        return 'secret data';
    }

    #[McpTool(name: 'twice')]
    #[Level('twice')]
    public function twice(): string
    {
        $this->log->toolRuns++;

        return 'ran';
    }

    #[McpTool(name: 'answers')]
    #[Level('answers')]
    public function answers(): string
    {
        $this->log->toolRuns++;

        return 'the tool ran';
    }

    #[McpResource(uri: 'test://readme', name: 'readme')]
    public function readme(): string
    {
        return 'read without the dispatcher';
    }
}
