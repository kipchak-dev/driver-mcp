<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

use Mcp\Server\Capability\Attribute\McpTool;

final class PrincipalTools
{
    #[McpTool(name: 'whoami')]
    public function whoami(): string
    {
        return 'served';
    }
}
