<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests;

use Kipchak\Driver\Mcp\Exception\ConfigurationException;
use Kipchak\Driver\Mcp\Mcp;
use Kipchak\Driver\Mcp\Tests\Fake\BootsDriver;
use Kipchak\Driver\Mcp\Tests\Fixtures\AskingTools;
use PHPUnit\Framework\TestCase;

/**
 * T17: request_state. On 2026-07-28 a tool that asks the user for input hands the client a signed state and reads it
 * back on the next call; the SDK refuses to mint one without a key.
 */
final class RequestStateTest extends TestCase
{
    use BootsDriver;

    private const string KEY = 'a-signing-key-of-at-least-32-bytes-long';

    /** @param array<string, mixed> $params */
    private static function call(array $params): array
    {
        $message = self::modern(1, 'tools/call', $params + ['name' => 'greet', 'arguments' => new \stdClass()]);
        // The client declares that it can answer an elicitation; the server asks only clients that can.
        $message['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = ['elicitation' => new \stdClass()];
        $request = self::request(
            $message,
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'greet'],
        );

        return self::json(Mcp::endpoint()($request));
    }

    public function testAToolCanAskForInputAndFinishOnTheNextCall(): void
    {
        $this->boot(['tools' => [AskingTools::class], 'request_state' => ['keys' => [self::KEY]]]);

        $first = self::call([]);
        $this->assertSame('input_required', $first['result']['resultType']);
        $this->assertArrayHasKey('name', $first['result']['inputRequests']);
        $state = $first['result']['requestState'];

        $second = self::call(['inputResponses' => ['name' => ['action' => 'accept', 'content' => ['name' => 'Ada']]], 'requestState' => $state]);
        $this->assertSame('Hello, Ada!', $second['result']['content'][0]['text']);
    }

    public function testATamperedStateIsRefused(): void
    {
        $this->boot(['tools' => [AskingTools::class], 'request_state' => ['keys' => [self::KEY]]]);
        $state = self::call([])['result']['requestState'];
        [$payload, $signature] = explode('.', $state);
        $forged = rtrim(strtr(base64_encode('{"exp":9999999999,"data":{"asked":"name"}}'), '+/', '-_'), '=') . '.' . $signature;

        $result = self::call(['inputResponses' => ['name' => ['action' => 'accept', 'content' => ['name' => 'Ada']]], 'requestState' => $forged]);

        $this->assertArrayHasKey('error', $result);
        $this->assertNotSame($payload, explode('.', $forged)[0]);
    }

    public function testWithoutAKeyTheToolCannotAsk(): void
    {
        $this->boot(['tools' => [AskingTools::class]]);

        $result = self::call([]);

        $this->assertArrayHasKey('error', $result, 'The SDK refuses to mint a state without a signing key');
    }

    public function testAShortKeyStopsBootWithoutShowingTheKey(): void
    {
        try {
            $this->boot(['tools' => [AskingTools::class], 'request_state' => ['keys' => [self::KEY, 'short-secret-value']]]);
            $this->fail('Boot succeeded with a short key.');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString("'request_state.keys' has key 2 of 18 bytes", $e->getMessage());
            $this->assertStringNotContainsString('short-secret-value', $e->getMessage());
        }
    }
}
