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
    private static function call(array $params, ?object $token = null, string $tool = 'greet'): array
    {
        $message = self::modern(1, 'tools/call', $params + ['name' => $tool, 'arguments' => new \stdClass()]);
        // The client declares that it can answer an elicitation; the server asks only clients that can.
        $message['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = ['elicitation' => new \stdClass()];
        $request = self::request(
            $message,
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $tool],
        );
        if ($token !== null) {
            $request = $request->withAttribute('kipchak.auth.token', $token);
        }

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

    private function bootWithPrincipal(): void
    {
        $this->boot([
            'tools' => [AskingTools::class],
            'request_state' => ['keys' => [self::KEY]],
            'principal_attribute' => 'kipchak.auth.token',
        ]);
    }

    private static function token(string $subject, array $extra = []): object
    {
        return (object) ($extra + ['iss' => 'https://idp.example.test/realms/example', 'sub' => $subject, 'azp' => 'assistant']);
    }

    private const array ANSWER = ['inputResponses' => ['name' => ['action' => 'accept', 'content' => ['name' => 'Ada']]]];

    /** A9: a state minted for one caller does not verify for another, even for the same tool and arguments. */
    public function testAStateMintedForOneCallerIsRefusedToAnother(): void
    {
        $this->bootWithPrincipal();
        $state = self::call([], self::token('alice'))['result']['requestState'];

        $asAlice = self::call(self::ANSWER + ['requestState' => $state], self::token('alice'));
        $this->assertSame('Hello, Ada!', $asAlice['result']['content'][0]['text'] ?? null, json_encode($asAlice));

        $asBob = self::call(self::ANSWER + ['requestState' => $state], self::token('bob'));
        $this->assertArrayHasKey('error', $asBob, 'Bob replayed Alice\'s state for the same tool and arguments');
    }

    /** A9: a state minted anonymously does not verify for an authenticated caller. */
    public function testAnAnonymousStateDoesNotVerifyForASignedInCaller(): void
    {
        $this->bootWithPrincipal();
        $state = self::call([])['result']['requestState'];

        $this->assertArrayHasKey('error', self::call(self::ANSWER + ['requestState' => $state], self::token('alice')));
    }

    /** A9: the access token the SDK sees carries the caller's issuer, client and subject, and no other claim. */
    public function testTheSdkSeesOnlyTheCallersIssuerClientAndSubject(): void
    {
        $this->bootWithPrincipal();

        $result = self::call([], self::token('alice', ['email' => 'alice@example.test', 'scope' => 'mcp admin', 'groups' => ['ops']]), 'claims');

        $this->assertSame(
            ['sub' => 'alice', 'iss' => 'https://idp.example.test/realms/example', 'client_id' => 'assistant'],
            json_decode($result['result']['content'][0]['text'], true),
        );
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
