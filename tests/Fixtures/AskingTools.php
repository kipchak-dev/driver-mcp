<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fixtures;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

/** A tool that asks the user for input before it answers, as the 2026-07-28 era does it. */
final class AskingTools
{
    #[McpTool(name: 'greet')]
    public function greet(RequestContext $context): CallToolResult|InputRequiredResult
    {
        $input = $context->getInputContext();
        if ($input === null || !$input->has('name')) {
            return new InputRequiredResult(
                ['name' => new ElicitRequest('What is your name?', new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']))],
                requestState: $context->mintRequestState(['asked' => 'name']),
            );
        }

        return new CallToolResult([new TextContent('Hello, ' . $input->elicitResult('name')->content['name'] . '!')]);
    }
}
