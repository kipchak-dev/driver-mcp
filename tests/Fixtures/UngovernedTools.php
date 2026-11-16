<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

use Mcp\Server\Capability\Attribute\McpTool;

final class UngovernedTools
{
    #[McpTool(name: 'ungoverned')]
    public function ungoverned(): string
    {
        return 'should never be served';
    }
}
