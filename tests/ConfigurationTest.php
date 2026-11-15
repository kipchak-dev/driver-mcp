<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests;

use Kipchak\Driver\Mcp\Exception\ConfigurationException;
use Kipchak\Driver\Mcp\Mcp;
use Kipchak\Driver\Mcp\Tests\Fake\ArrayCache;
use Kipchak\Driver\Mcp\Tests\Fake\BootsDriver;
use Kipchak\Driver\Mcp\Tests\Fixtures\Clock;
use Kipchak\Driver\Mcp\Tests\Fixtures\DuplicateStatusTools;
use Kipchak\Driver\Mcp\Tests\Fixtures\StatusTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T1: every configuration error stops the API at boot, with a message naming the server and the key.
 */
final class ConfigurationTest extends TestCase
{
    use BootsDriver;

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown tool class' => [['tools' => ['App\\Mcp\\Missing']], "'tools' names the class App\\Mcp\\Missing, which does not exist"];
        yield 'class listed twice' => [['tools' => [StatusTools::class, StatusTools::class]], "'tools' lists a class more than once"];
        yield 'nothing to offer' => [['tools' => []], "'tools' and 'discovery' are both empty"];
        yield 'empty hosts' => [['allowed_hosts' => []], "'allowed_hosts' is empty"];
        yield 'host with a port' => [['allowed_hosts' => ['api.example.test:8080']], "Give host names only"];
        yield 'host with a scheme' => [['allowed_hosts' => ['https://api.example.test']], "Give host names only"];
        yield 'missing name' => [['name' => ''], "'name' must be a non-empty string"];
        yield 'missing version' => [['version' => null], "'version' must be a non-empty string"];
        yield 'discovery outside the project' => [['tools' => [], 'discovery' => ['no/such/dir'], 'base_path' => __DIR__], "'discovery' names no/such/dir, which is not a directory"];
        yield 'sdk attribute as principal' => [['principal_attribute' => 'mcp.principal'], "is the SDK's own attribute"];
        yield 'tiny body limit' => [['max_body_bytes' => 10], "'max_body_bytes' must be a whole number of bytes, at least 1024"];
        // A1: a session store without a principal would give sessions that any caller can use.
        yield 'sessions without a principal (A1)' => [['sessions' => 'test.cache'], "'principal_attribute' must be set when 'sessions' is set"];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalid')]
    public function testAConfigurationErrorStopsBootNamingTheServerAndKey(array $overrides, string $expected): void
    {
        try {
            $this->boot($overrides);
            $this->fail('Boot succeeded with an invalid configuration.');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString("MCP server 'default' in kipchak.mcp", $e->getMessage());
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testAValidConfigurationBoots(): void
    {
        $container = $this->boot();

        $this->assertTrue($container->has('drivers.mcp.default'));
    }

    public function testSessionsWithAPrincipalBoot(): void
    {
        $container = new \DI\Container();
        $container->set('test.cache', new ArrayCache());

        $this->boot(['sessions' => 'test.cache', 'principal_attribute' => 'auth.subject'], $container);

        $this->assertTrue($container->has('drivers.mcp.default'));
    }

    public function testASessionKeyThatIsNotAPsr16CacheStopsTheBuild(): void
    {
        $container = new \DI\Container();
        $container->set('test.cache', new Clock());
        $this->boot(['sessions' => 'test.cache', 'principal_attribute' => 'auth.subject'], $container);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("names 'test.cache', which is a " . Clock::class . ', not a PSR-16 cache');
        Mcp::get();
    }

    public function testASessionKeyMissingFromTheContainerStopsTheBuild(): void
    {
        $this->boot(['sessions' => 'drivers.cache.valkey.cache', 'principal_attribute' => 'auth.subject']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("names 'drivers.cache.valkey.cache', which is not in the container");
        Mcp::get();
    }

    public function testTwoClassesDeclaringOneToolNameStopTheBuild(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("declares the tool 'status' in both " . StatusTools::class . ' and ' . DuplicateStatusTools::class);
        $this->boot(['tools' => [StatusTools::class, DuplicateStatusTools::class]]);
    }

    public function testAClassWithNoMcpAttributesStopsTheBuild(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('names ' . Clock::class . ', which declares no #[McpTool]');
        $this->boot(['tools' => [Clock::class]]);
    }

    public function testAnUnknownServerNameForTheEndpointIsRefused(): void
    {
        $this->boot();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("kipchak.mcp has no server 'admin'. Servers configured: default.");
        Mcp::endpoint('admin');
    }
}
