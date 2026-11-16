<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures\Discovered;

use Mcp\Server\Capability\Attribute\McpTool;

final class DiscoveredUngoverned
{
    #[McpTool(name: 'discovered_ungoverned')]
    public function run(): string
    {
        return 'should never be served';
    }
}
