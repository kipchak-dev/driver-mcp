<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp;

use Kipchak\Driver\Mcp\Library\RefusalLogging;
use Kipchak\Driver\Mcp\Library\WarningsOnly;
use Kipchak\Driver\Mcp\Library\ServerSettings;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The route handler for one MCP server: $app->any('/mcp', Mcp::endpoint('default')).
 *
 * The server is built once per worker. Each request gets a transport of its own, which holds that request's state
 * and is released with it, so nothing about one caller survives into the next request in FrankenPHP worker mode.
 */
final readonly class Endpoint
{
    /**
     * @param \Closure(): Server $server
     */
    public function __construct(
        private ServerSettings $settings,
        private \Closure $server,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ?ResponseInterface $response = null, array $args = []): ResponseInterface
    {
        // Only the authentication middleware's attribute decides who the caller is. Anything a caller managed to put
        // in the SDK's own attributes is removed first.
        $request = $request
            ->withoutAttribute(StreamableHttpTransport::PRINCIPAL_ATTRIBUTE)
            ->withoutAttribute(AccessToken::class);

        $principalAttribute = $this->settings->principalAttribute;
        if ($principalAttribute !== null) {
            $identity = self::identity($request->getAttribute($principalAttribute), $this->settings->principalClaim);
            if ($identity !== null) {
                // The principal binds a session to its caller. The access token, built from the caller's issuer,
                // client and subject only, binds each signed request state to its caller as well: the SDK's
                // OriginatingRequestBinder reads it. No other claim of the original token is passed on.
                $request = $request
                    ->withAttribute(StreamableHttpTransport::PRINCIPAL_ATTRIBUTE, $identity['principal'])
                    ->withAttribute(AccessToken::class, new AccessToken([], $identity['claims']));
            }
        }

        $transport = new StreamableHttpTransport(
            $request,
            $this->responseFactory,
            $this->streamFactory,
            new WarningsOnly($this->logger),
            [
                new RefusalLogging($this->settings->server, $this->logger),
                new CorsMiddleware($this->settings->allowedOrigins),
                new DnsRebindingProtectionMiddleware($this->settings->allowedHosts, $this->settings->allowedOrigins, $this->responseFactory, $this->streamFactory),
            ],
            $this->settings->maxBodyBytes,
        );

        return ($this->server)()->run($transport);
    }

    /**
     * The caller's identity from the authentication middleware's attribute: a string as it is, or the named claim of
     * a verified token (an object or array, as auth-jwks and auth-jwt set it), together with its issuer and client
     * when it names them. Anything else is no identity, and a request with no identity cannot use a session.
     *
     * @return array{principal: string, claims: array<string, string>}|null
     */
    private static function identity(mixed $value, string $claim): ?array
    {
        if (is_string($value)) {
            return $value !== '' ? ['principal' => $value, 'claims' => ['sub' => $value]] : null;
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return null;
        }

        $subject = $value[$claim] ?? null;
        if (!is_string($subject) || $subject === '') {
            return null;
        }

        $claims = ['sub' => $subject];
        $issuer = $value['iss'] ?? null;
        if (is_string($issuer) && $issuer !== '') {
            $claims['iss'] = $issuer;
        }
        $client = $value['client_id'] ?? $value['azp'] ?? null;
        if (is_string($client) && $client !== '') {
            $claims['client_id'] = $client;
        }

        // A subject is unique only within its issuer, so a token that names its issuer gives the pair. JSON keeps the
        // two parts apart whatever characters they hold.
        $principal = isset($claims['iss'])
            ? json_encode([$claims['iss'], $subject], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : $subject;

        return ['principal' => $principal, 'claims' => $claims];
    }
}
