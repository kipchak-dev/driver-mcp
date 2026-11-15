<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests;

use DI\Container;
use Kipchak\Driver\Mcp\Library\RefusalLogging;
use Kipchak\Driver\Mcp\Mcp;
use Kipchak\Driver\Mcp\Tests\Fake\ArrayCache;
use Kipchak\Driver\Mcp\Tests\Fake\BootsDriver;
use Kipchak\Driver\Mcp\Tests\Fixtures\PrincipalTools;
use Mcp\Server\Server;
use PHPUnit\Framework\TestCase;

/**
 * T2 to T7 and T14: the endpoint, through the driver, as a Slim route would call it.
 */
final class EndpointTest extends TestCase
{
    use BootsDriver;

    /** T2: the server is registered, built once, and its handlers come from the Kipchak container. */
    public function testTheServerIsBuiltOnceAndHandlersComeFromTheContainer(): void
    {
        $this->boot();

        $this->assertInstanceOf(Server::class, Mcp::get());
        $this->assertSame(Mcp::get(), Mcp::get('default'), 'One server per worker');

        $endpoint = Mcp::endpoint();
        $endpoint(self::request(self::initialize()));
        $result = self::json($endpoint(self::request(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'status', 'arguments' => new \stdClass()]],
            ['MCP-Protocol-Version' => '2025-11-25'],
        )));

        // StatusTools needs a Clock in its constructor: only the container can supply it.
        $this->assertFalse($result['result']['isError']);
        $this->assertStringContainsString('2026-11-15T00:00:00Z', $result['result']['content'][0]['text']);
    }

    /** A request writes nothing below warning to the API log: the SDK logs several info lines per request. */
    public function testARequestLogsNothingBelowWarning(): void
    {
        $this->boot();
        $endpoint = Mcp::endpoint();
        $endpoint(self::request(self::initialize()));
        $endpoint(self::request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'add', 'arguments' => ['a' => 1, 'b' => 2]]], ['MCP-Protocol-Version' => '2025-11-25']));

        $this->assertSame([], $this->log->records);
    }

    /** T3: the handshake era, without sessions. */
    public function testHandshakeEraWithoutSessions(): void
    {
        $this->boot();
        $endpoint = Mcp::endpoint();

        $init = $endpoint(self::request(self::initialize()));
        $this->assertSame(200, $init->getStatusCode());
        $this->assertSame('2025-11-25', self::json($init)['result']['protocolVersion']);
        $this->assertSame('Test API', self::json($init)['result']['serverInfo']['name']);
        $this->assertFalse($init->hasHeader('Mcp-Session-Id'), 'No session is opened unless the configuration asks for one');

        $list = self::json($endpoint(self::request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['MCP-Protocol-Version' => '2025-11-25'])));
        $this->assertSame(['add', 'status'], self::toolNames($list));

        $call = self::json($endpoint(self::request(
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'add', 'arguments' => ['a' => 2, 'b' => 3]]],
            ['MCP-Protocol-Version' => '2025-11-25'],
        )));
        $this->assertSame('5', $call['result']['content'][0]['text']);
    }

    /** T4: the 2026-07-28 era, with no initialize. */
    public function testPerRequestEraWithoutInitialize(): void
    {
        $this->boot();
        $endpoint = Mcp::endpoint();

        $list = $endpoint(self::request(self::modern(1, 'tools/list'), ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']));
        $this->assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        $this->assertSame(['add', 'status'], self::toolNames(self::json($list)));

        $call = $endpoint(self::request(
            self::modern(2, 'tools/call', ['name' => 'add', 'arguments' => ['a' => 20, 'b' => 22]]),
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'add'],
        ));
        $this->assertSame(200, $call->getStatusCode(), (string) $call->getBody());
        $this->assertSame('42', self::json($call)['result']['content'][0]['text']);
    }

    /** T5: with sessions on, a session belongs to the principal who opened it. */
    public function testASessionCannotBeUsedByAnotherPrincipal(): void
    {
        $container = new Container();
        $container->set('test.cache', new ArrayCache());
        $this->boot(['sessions' => 'test.cache', 'principal_attribute' => 'auth.subject', 'tools' => [PrincipalTools::class]], $container);
        $endpoint = Mcp::endpoint();

        $init = $endpoint(self::request(self::initialize())->withAttribute('auth.subject', 'alice'));
        $session = $init->getHeaderLine('Mcp-Session-Id');
        $this->assertNotSame('', $session, 'A session is opened when the configuration asks for one');

        $call = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => new \stdClass()]];
        $headers = ['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Session-Id' => $session];

        $asAlice = $endpoint(self::request($call, $headers)->withAttribute('auth.subject', 'alice'));
        $this->assertSame(200, $asAlice->getStatusCode());

        $asBob = $endpoint(self::request($call, $headers)->withAttribute('auth.subject', 'bob'));
        $this->assertSame(404, $asBob->getStatusCode(), 'Another principal must not reach the session');

        $anonymous = $endpoint(self::request($call, $headers));
        $this->assertSame(404, $anonymous->getStatusCode(), 'A request with no principal must not reach the session');
    }

    /** A verified token, as auth-jwks and auth-jwt set it, gives its subject claim as the principal. */
    public function testAVerifiedTokenGivesItsSubjectAsThePrincipal(): void
    {
        $container = new Container();
        $container->set('test.cache', new ArrayCache());
        $this->boot(['sessions' => 'test.cache', 'principal_attribute' => 'kipchak.auth.token', 'tools' => [PrincipalTools::class]], $container);
        $endpoint = Mcp::endpoint();
        $token = static fn (?string $sub): object => (object) array_filter(['sub' => $sub, 'iss' => 'https://idp.example.test/realms/test']);

        $session = $endpoint(self::request(self::initialize())->withAttribute('kipchak.auth.token', $token('alice')))->getHeaderLine('Mcp-Session-Id');
        $this->assertNotSame('', $session);
        $call = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => new \stdClass()]];
        $headers = ['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Session-Id' => $session];

        $this->assertSame(200, $endpoint(self::request($call, $headers)->withAttribute('kipchak.auth.token', $token('alice')))->getStatusCode());
        $this->assertSame(404, $endpoint(self::request($call, $headers)->withAttribute('kipchak.auth.token', $token('bob')))->getStatusCode());
        $this->assertSame(404, $endpoint(self::request($call, $headers)->withAttribute('kipchak.auth.token', $token(null)))->getStatusCode(), 'A token without the claim is no principal');
    }

    /** A8: a subject is unique only within its issuer, so the same sub from two issuers is two principals. */
    public function testTheSameSubjectFromAnotherIssuerIsAnotherPrincipal(): void
    {
        $container = new Container();
        $container->set('test.cache', new ArrayCache());
        $this->boot(['sessions' => 'test.cache', 'principal_attribute' => 'kipchak.auth.token', 'tools' => [PrincipalTools::class]], $container);
        $endpoint = Mcp::endpoint();
        $fromA = (object) ['sub' => 'alice', 'iss' => 'https://idp-a.example.test/realms/a'];
        $fromB = (object) ['sub' => 'alice', 'iss' => 'https://idp-b.example.test/realms/b'];

        $session = $endpoint(self::request(self::initialize())->withAttribute('kipchak.auth.token', $fromA))->getHeaderLine('Mcp-Session-Id');
        $call = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => new \stdClass()]];
        $headers = ['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Session-Id' => $session];

        $this->assertSame(200, $endpoint(self::request($call, $headers)->withAttribute('kipchak.auth.token', $fromA))->getStatusCode());
        $this->assertSame(404, $endpoint(self::request($call, $headers)->withAttribute('kipchak.auth.token', $fromB))->getStatusCode());
        $this->assertSame(404, $endpoint(self::request($call, $headers)->withAttribute('kipchak.auth.token', 'alice'))->getStatusCode(), 'A plain string alice is not the token alice of issuer A');
    }

    /** The principal comes only from the configured attribute; a value placed in the SDK's own attribute is dropped. */
    public function testOnlyTheConfiguredAttributeDecidesThePrincipal(): void
    {
        $container = new Container();
        $container->set('test.cache', new ArrayCache());
        $this->boot(['sessions' => 'test.cache', 'principal_attribute' => 'auth.subject', 'tools' => [PrincipalTools::class]], $container);
        $endpoint = Mcp::endpoint();

        $session = $endpoint(self::request(self::initialize())->withAttribute('auth.subject', 'alice'))->getHeaderLine('Mcp-Session-Id');
        $call = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => new \stdClass()]];
        $forged = self::request($call, ['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Session-Id' => $session])
            ->withAttribute('mcp.principal', 'alice');

        $this->assertSame(404, $endpoint($forged)->getStatusCode());
    }

    /** T6: a Host outside allowed_hosts is refused; a listed host, with or without a port, is served. */
    public function testHostProtection(): void
    {
        $this->boot();
        $endpoint = Mcp::endpoint();

        $this->assertSame(403, $endpoint(self::request(self::initialize(), host: 'evil.example'))->getStatusCode());
        $this->assertSame(200, $endpoint(self::request(self::initialize(), host: 'api.example.test'))->getStatusCode());
        $this->assertSame(200, $endpoint(self::request(self::initialize(), host: 'api.example.test:8443'))->getStatusCode());
    }

    /** T7: a body over max_body_bytes is refused with 413. */
    public function testBodyLimit(): void
    {
        $this->boot(['max_body_bytes' => 2048]);
        $endpoint = Mcp::endpoint();

        $message = json_encode(self::initialize(), JSON_THROW_ON_ERROR);
        $atLimit = $message . str_repeat(' ', 2048 - strlen($message));
        $overLimit = $atLimit . ' ';

        $this->assertSame(200, $endpoint(self::request($atLimit))->getStatusCode());
        $this->assertSame(413, $endpoint(self::request($overLimit))->getStatusCode());
    }

    /** T14: a refused Host is logged, escaped and shortened, and the response body is the SDK's, unchanged. */
    public function testARefusedHostIsLoggedSafely(): void
    {
        $this->boot();
        $endpoint = Mcp::endpoint();

        $response = $endpoint(self::request(self::initialize(), host: 'evil.example'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(RefusalLogging::HOST_REFUSED, (string) $response->getBody());
        $this->assertStringNotContainsString('api.example.test', (string) $response->getBody(), 'The allowed list is never shown to a caller');
        $warnings = $this->log->messages('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("Host 'evil.example'", $warnings[0]);
        $this->assertStringContainsString("'allowed_hosts'", $warnings[0]);
        $this->assertStringContainsString('MCP_HOST', $warnings[0]);
    }

    /** T14, A3: a Host that tries to add a line to the log is logged as one escaped, capped line. */
    public function testARefusedHostCannotInjectLogLines(): void
    {
        // Slim's PSR-7 refuses a header value with a newline, so the request is a stub carrying the value as a web
        // server could pass it on, and the handler stands in for the SDK's refusal.
        $hostile = "evil.example\nCRITICAL forged entry\x07" . str_repeat('a', 400);
        $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnMap([['Host', $hostile], ['Origin', '']]);
        $refusal = (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(403);
        $refusal->getBody()->write(RefusalLogging::HOST_REFUSED);
        $handler = $this->createStub(\Psr\Http\Server\RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($refusal);
        $this->log = new Fake\RecordingLogger();

        $response = (new RefusalLogging('default', $this->log))->process($request, $handler);

        $this->assertSame(RefusalLogging::HOST_REFUSED, (string) $response->getBody(), 'The body is passed on unchanged');
        $warnings = $this->log->messages('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringNotContainsString("\n", $warnings[0]);
        $this->assertStringNotContainsString("\x07", $warnings[0]);
        $this->assertStringContainsString('evil.example\x0ACRITICAL forged entry\x07', $warnings[0]);
        preg_match("/Host '([^']*)'/", $warnings[0], $m);
        $this->assertSame(RefusalLogging::MAX_LOGGED_LENGTH, mb_strlen($m[1]));
        $this->assertStringEndsWith('...', $m[1]);
    }

    public function testEscapeLeavesOrdinaryHostsAlone(): void
    {
        $this->assertSame('api.example.test:8443', RefusalLogging::escape('api.example.test:8443'));
        $this->assertSame('[::1]', RefusalLogging::escape('[::1]'));
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function toolNames(array $result): array
    {
        $names = array_map(static fn (array $tool): string => $tool['name'], $result['result']['tools']);
        sort($names);

        return $names;
    }
}
