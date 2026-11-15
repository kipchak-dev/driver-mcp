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
    /** What the SDK tells a tool about its caller: the claims of the access token the driver built. */
    #[McpTool(name: 'claims')]
    public function claims(RequestContext $context): string
    {
        return json_encode($context->getAccessToken()?->getClaims(), JSON_THROW_ON_ERROR);
    }

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
