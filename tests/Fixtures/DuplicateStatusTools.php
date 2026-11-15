<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

use Mcp\Server\Capability\Attribute\McpTool;

final class DuplicateStatusTools
{
    #[McpTool(name: 'status')]
    public function status(): string
    {
        return 'duplicate';
    }
}
