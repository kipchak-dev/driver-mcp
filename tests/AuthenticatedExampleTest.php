<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests;

use DI\Container;
use Firebase\JWT\JWT;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Filecache\Filecache;
use Kipchak\Driver\Logger\Logger;
use Kipchak\Driver\Mcp\Mcp;
use Kipchak\Driver\Mcp\Tests\Fake\ArrayCache;
use Kipchak\Driver\Mcp\Tests\Fake\BootsDriver;
use Kipchak\Driver\Mcp\Tests\Fixtures\PrincipalTools;
use Kipchak\Middleware\Auth\JWKS\Handlers\AuthJWKS;
use Monolog\Handler\NullHandler;
use Monolog\Logger as MonoLogger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * T13: the documented route behind authentication, examples/authenticated-route.php, run as written, in front of a
 * real kipchak/middleware-auth-jwks with a key set published as an identity provider would publish it. The
 * kipchak.dev page shows the same file; the site's build check compares the two.
 */
final class AuthenticatedExampleTest extends TestCase
{
    use BootsDriver;

    private const string ISSUER = 'https://idp.example.test/realms/example';
    private const string EXAMPLE = __DIR__ . '/../examples/authenticated-route.php';

    private string $privateKey = '';
    private string $jwksFile = '';
    private App $app;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $base64url = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $this->jwksFile = (string) tempnam(sys_get_temp_dir(), 'mcp-jwks');
        file_put_contents($this->jwksFile, json_encode(['keys' => [[
            'kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig', 'n' => $base64url($rsa['n']), 'e' => $base64url($rsa['e']),
        ]]]));

        $container = new Container();
        $container->set('test.cache', new ArrayCache());
        $this->boot([
            'tools' => [PrincipalTools::class],
            'sessions' => 'test.cache',
            // As the example's comment says to set them.
            'principal_attribute' => AuthJWKS::ATTRIBUTE_TOKEN,
            'principal_claim' => 'sub',
        ], $container);

        $config = $container->get('drivers.config');
        $config['kipchak.auth.jwks'] = [
            'enabled' => false,
            'ignore_options' => true,
            'jwksUri' => $this->jwksFile,
            'issuer' => self::ISSUER,
            'audience' => ['assistant'],
            'token_type' => 'Bearer',
        ];
        $container->set('drivers.config', $config);
        $container->set('drivers.logger', new MonoLogger('test', [new NullHandler()]));
        $container->set('drivers.cache.file', new FilesystemAdapter('mcp-jwks-' . bin2hex(random_bytes(4)), 0, sys_get_temp_dir() . '/mcp-jwks-cache'));
        foreach ([Config::class, Logger::class, Filecache::class] as $driver) {
            (new \ReflectionProperty($driver, 'container'))->setValue(null, $container);
        }

        $app = AppFactory::createFromContainer($container);
        (static function (App $app): void {
            require self::EXAMPLE;
        })($app);
        $this->app = $app;
    }

    protected function tearDown(): void
    {
        @unlink($this->jwksFile);
    }

    private function token(string $subject, string $scope = 'mcp'): string
    {
        return JWT::encode([
            'iss' => self::ISSUER, 'sub' => $subject, 'azp' => 'assistant', 'aud' => 'account', 'typ' => 'Bearer',
            'scope' => $scope, 'iat' => time(), 'exp' => time() + 300,
        ], $this->privateKey, 'RS256', 'k1');
    }

    /** @param array<string, mixed> $message */
    private function send(array $message, ?string $token, string $session = ''): ResponseInterface
    {
        $request = self::request($message, ['MCP-Protocol-Version' => '2025-11-25']);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($session !== '') {
            $request = $request->withHeader('Mcp-Session-Id', $session);
        }

        return $this->app->handle($request);
    }

    public function testARequestWithoutATokenIsRefusedBeforeTheServerSeesIt(): void
    {
        $this->assertSame(401, $this->send(self::initialize(), null)->getStatusCode());
    }

    public function testATokenWithoutTheMcpScopeIsRefused(): void
    {
        $this->assertSame(403, $this->send(self::initialize(), $this->token('alice', 'email'))->getStatusCode());
    }

    public function testAValidTokenListsAndCallsTheToolAndOwnsItsSession(): void
    {
        $init = $this->send(self::initialize(), $this->token('alice'));
        $this->assertSame(200, $init->getStatusCode(), (string) $init->getBody());
        $session = $init->getHeaderLine('Mcp-Session-Id');
        $this->assertNotSame('', $session);

        $list = $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $this->token('alice'), $session);
        $this->assertSame(['whoami'], array_column(self::json($list)['result']['tools'], 'name'));

        $call = ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => new \stdClass()]];
        $this->assertSame(200, $this->send($call, $this->token('alice'), $session)->getStatusCode());
        $this->assertSame(404, $this->send($call, $this->token('bob'), $session)->getStatusCode(), 'Another subject cannot use the session');
    }
}
