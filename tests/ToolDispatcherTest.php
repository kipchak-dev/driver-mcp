<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests;

use DI\Container;
use Kipchak\Driver\Mcp\Exception\ConfigurationException;
use Kipchak\Driver\Mcp\Grant;
use Kipchak\Driver\Mcp\Library\DispatchingReferenceHandler;
use Kipchak\Driver\Mcp\Mcp;
use Kipchak\Driver\Mcp\Tests\Fake\BootsDriver;
use Kipchak\Driver\Mcp\Tests\Fixtures\DispatchLog;
use Kipchak\Driver\Mcp\Tests\Fixtures\FixtureDispatcher;
use Kipchak\Driver\Mcp\Tests\Fixtures\GovernedTools;
use Kipchak\Driver\Mcp\Tests\Fixtures\StatusTools;
use Kipchak\Driver\Mcp\Tests\Fixtures\UngovernedTools;
use Kipchak\Driver\Mcp\ToolCall;
use Kipchak\Driver\Mcp\ToolDefinition;
use Kipchak\Driver\Mcp\ToolDispatcher;
use Mcp\Schema\Tool;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Server\Capability\Registry\ElementReference;
use Mcp\Server\Capability\Registry\ToolReference;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * T16, the driver part: the tool_dispatcher setting (1.1.0). One dispatcher decides every tool call; a tool it refuses
 * is never served; a fault inside it closes the call with no data.
 */
final class ToolDispatcherTest extends TestCase
{
    use BootsDriver;

    private DispatchLog $calls;

    private function bootGoverned(array $overrides = []): Container
    {
        $container = new Container();
        $this->calls = new DispatchLog();
        $container->set(DispatchLog::class, $this->calls);

        return $this->boot(array_replace([
            'tools' => [GovernedTools::class],
            'tool_dispatcher' => FixtureDispatcher::class,
            'principal_attribute' => 'auth.token',
        ], $overrides), $container);
    }

    /** @return array<string, mixed> */
    private function call(string $tool, array $arguments = [], ?object $token = null): array
    {
        $request = self::request(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments]],
            ['MCP-Protocol-Version' => '2025-11-25'],
        );
        if ($token !== null) {
            $request = $request->withAttribute('auth.token', $token);
        }
        $endpoint = Mcp::endpoint();
        $endpoint(self::request(self::initialize()));

        return self::json($endpoint($request));
    }

    private static function token(): object
    {
        return (object) ['iss' => 'https://id.example.test/realms/test', 'sub' => 'user-1', 'azp' => 'console', 'email' => 'user@example.test', 'scope' => 'openid mcp'];
    }

    public function testEveryToolIsPutToTheDispatcherAtBoot(): void
    {
        $this->bootGoverned();

        $this->assertSame(['answers', 'echo', 'fault', 'mine', 'twice'], self::sorted($this->calls->accepted));
    }

    public function testAToolTheDispatcherRefusesStopsTheApiAtBoot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Tool 'ungoverned' declares no level.");

        $this->bootGoverned(['tools' => [GovernedTools::class, UngovernedTools::class]]);
    }

    public function testADiscoveredToolTheDispatcherRefusesStopsTheApiAtBoot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Tool 'discovered_ungoverned' declares no level.");

        $this->bootGoverned(['tools' => [], 'discovery' => ['Fixtures/Discovered'], 'base_path' => __DIR__]);
    }

    public function testTheSettingMustNameAToolDispatcher(): void
    {
        $cases = [
            'No\\Such\\Dispatcher' => "'tool_dispatcher' must be null or the name of a class",
            StatusTools::class => "'tool_dispatcher' names " . StatusTools::class . ', which does not implement',
        ];
        foreach ($cases as $class => $message) {
            try {
                $this->bootGoverned(['tool_dispatcher' => $class]);
                $this->fail("{$class} was accepted as a tool dispatcher");
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testAPublicToolRunsForAnAnonymousCaller(): void
    {
        $this->bootGoverned();

        $result = $this->call('echo', ['value' => 'hello']);

        $this->assertFalse($result['result']['isError']);
        $this->assertSame('hello', $result['result']['content'][0]['text']);
        $this->assertNull($this->calls->calls[0]->caller);
        $this->assertSame(['value' => 'hello'], $this->calls->calls[0]->arguments, 'The SDK\'s own entries are not passed to the dispatcher');
    }

    public function testARefusalReturnsAToolErrorAndTheToolDoesNotRun(): void
    {
        $this->bootGoverned();

        $result = $this->call('mine');

        $this->assertTrue($result['result']['isError']);
        $this->assertSame('Sign in to use this tool.', $result['result']['content'][0]['text']);
        $this->assertArrayNotHasKey('structuredContent', $result['result']);
        $this->assertSame(0, $this->calls->toolRuns);
    }

    public function testTheCallerIsTheIssuerClientAndSubjectOnly(): void
    {
        $this->bootGoverned();

        $result = $this->call('mine', [], self::token());

        $this->assertSame(['sub' => 'user-1', 'iss' => 'https://id.example.test/realms/test', 'client_id' => 'console'], $this->calls->calls[0]->caller);
        $this->assertSame('grant={"subject":"user-1"}', $result['result']['content'][0]['text'], 'The tool reads the grant the dispatcher gave');
    }

    public function testAFaultInsideTheDispatcherClosesTheCallWithNoData(): void
    {
        $this->bootGoverned();

        $result = $this->call('fault', [], self::token());

        $this->assertArrayNotHasKey('result', $result);
        $this->assertSame(-32603, $result['error']['code']);
        $this->assertStringNotContainsString('internal detail', json_encode($result));
        $this->assertSame(0, $this->calls->toolRuns);
    }

    public function testTheToolRunsAtMostOnceInACall(): void
    {
        $this->bootGoverned();

        $result = $this->call('twice');

        $this->assertSame(-32603, $result['error']['code']);
        $this->assertSame(1, $this->calls->toolRuns);
    }

    public function testTheDispatcherMayAnswerWithoutRunningTheTool(): void
    {
        $this->bootGoverned();

        $result = $this->call('answers');

        $this->assertSame('answered by the dispatcher', $result['result']['content'][0]['text']);
        $this->assertSame(0, $this->calls->toolRuns);
    }

    public function testResourcesDoNotGoThroughTheDispatcher(): void
    {
        $this->bootGoverned();
        $endpoint = Mcp::endpoint();
        $endpoint(self::request(self::initialize()));

        $result = self::json($endpoint(self::request(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/read', 'params' => ['uri' => 'test://readme']], ['MCP-Protocol-Version' => '2025-11-25'])));

        $this->assertSame('read without the dispatcher', $result['result']['contents'][0]['text']);
        $this->assertSame([], $this->calls->calls);
    }

    /** The grant is in the session only while the tool runs, and replaces anything left there. */
    public function testTheGrantIsSetForTheCallOnlyAndRemovedAfterIt(): void
    {
        $session = new Session(new InMemorySessionStore());
        $session->set(Grant::SESSION_KEY, 'left by someone else');
        $session->set(AccessToken::class, new AccessToken([], ['sub' => 'user-1']));
        $seenByTool = [];
        $inner = new class ($seenByTool) implements ReferenceHandlerInterface {
            public function __construct(private array &$seen)
            {
            }

            public function handle(ElementReference $reference, array $arguments): mixed
            {
                $this->seen[] = $arguments['_session']->get(Grant::SESSION_KEY);
                if (($arguments['fail'] ?? false) === true) {
                    throw new \RuntimeException('tool failed');
                }

                return 'ok';
            }
        };
        $dispatcher = new class implements ToolDispatcher {
            public function accept(ToolDefinition $tool): void
            {
            }

            public function dispatch(ToolCall $call, \Closure $next): mixed
            {
                return $next(($call->arguments['grant'] ?? false) ? ['organisation' => 'org-1'] : null);
            }
        };
        $handler = new DispatchingReferenceHandler($inner, $dispatcher);
        $reference = new ToolReference(new Tool('t', null, ['type' => 'object'], null, null), static fn () => null);

        $handler->handle($reference, ['grant' => true, '_session' => $session]);
        $this->assertNull($session->get(Grant::SESSION_KEY), 'Removed after the call');

        try {
            $handler->handle($reference, ['grant' => true, 'fail' => true, '_session' => $session]);
        } catch (\RuntimeException) {
        }
        $this->assertNull($session->get(Grant::SESSION_KEY), 'Removed after a call whose tool threw');

        $session->set(Grant::SESSION_KEY, 'left by someone else');
        $handler->handle($reference, ['grant' => false, '_session' => $session]);

        $this->assertSame([['organisation' => 'org-1'], ['organisation' => 'org-1'], null], $seenByTool, 'A call without a grant never sees an earlier value');
    }

    /** The handler keeps only the issuer, client and subject even if the session's token carries more claims. */
    public function testTheHandlerPassesNoOtherClaimToTheDispatcher(): void
    {
        $session = new Session(new InMemorySessionStore());
        $session->set(AccessToken::class, new AccessToken(['mcp'], ['sub' => 'user-1', 'iss' => 'issuer', 'client_id' => 'console', 'email' => 'user@example.test', 'org' => 'org-9']));
        $inner = new class implements ReferenceHandlerInterface {
            public function handle(ElementReference $reference, array $arguments): mixed
            {
                return 'ok';
            }
        };
        $dispatcher = new class implements ToolDispatcher {
            public ?ToolCall $call = null;

            public function accept(ToolDefinition $tool): void
            {
            }

            public function dispatch(ToolCall $call, \Closure $next): mixed
            {
                $this->call = $call;

                return $next();
            }
        };

        (new DispatchingReferenceHandler($inner, $dispatcher))->handle(
            new ToolReference(new Tool('t', null, ['type' => 'object'], null, null), static fn () => null),
            ['_session' => $session, '_request' => null],
        );

        $this->assertSame(['sub' => 'user-1', 'iss' => 'issuer', 'client_id' => 'console'], $dispatcher->call?->caller);
        $this->assertSame([], $dispatcher->call?->arguments);
    }

    /** The dispatcher the documentation shows, examples/tool-dispatcher.php, works as the page says. */
    public function testTheDocumentedExampleWorks(): void
    {
        require_once __DIR__ . '/../examples/tool-dispatcher.php';
        $this->boot([
            'tools' => [\Kipchak\Driver\Mcp\Tests\Fixtures\ExampleDispatcherTools::class],
            'tool_dispatcher' => \Api\Mcp\Dispatcher::class,
            'principal_attribute' => 'auth.token',
        ]);

        $this->assertSame('hello', $this->call('hello')['result']['content'][0]['text']);
        $this->assertSame('Sign in to use this tool.', $this->call('my_account')['result']['content'][0]['text']);
        $this->assertSame('account of user-1', $this->call('my_account', [], self::token())['result']['content'][0]['text']);

        $this->expectExceptionMessage("MCP tool 'ungoverned' declares neither #[SignIn] nor #[Anyone].");
        $this->boot(['tools' => [UngovernedTools::class], 'tool_dispatcher' => \Api\Mcp\Dispatcher::class]);
    }

    public function testWithoutADispatcherEveryToolRunsAsBefore(): void
    {
        $this->boot(['tools' => [StatusTools::class]]);
        $endpoint = Mcp::endpoint();
        $endpoint(self::request(self::initialize()));

        $result = self::json($endpoint(self::request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'add', 'arguments' => ['a' => 1, 'b' => 2]]], ['MCP-Protocol-Version' => '2025-11-25'])));

        $this->assertSame('3', $result['result']['content'][0]['text']);
    }

    /** @param list<string> $names */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
