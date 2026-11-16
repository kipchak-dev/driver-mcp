<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

use Api\Mcp\Anyone;
use Api\Mcp\SignIn;
use Kipchak\Driver\Mcp\Grant;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

/** Tools for examples/tool-dispatcher.php, which the documentation shows. */
final class ExampleDispatcherTools
{
    #[McpTool(name: 'hello')]
    #[Anyone]
    public function hello(): string
    {
        return 'hello';
    }

    #[McpTool(name: 'my_account')]
    #[SignIn]
    public function myAccount(RequestContext $context): string
    {
        return 'account of ' . Grant::from($context)['subject'];
    }
}
