<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp;

use Kipchak\Driver\Mcp\Library\RefusalLogging;
use Kipchak\Driver\Mcp\Library\ServerSettings;
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
        $principalAttribute = $this->settings->principalAttribute;
        // Only the authentication middleware's attribute decides the principal. Anything a caller managed to put in
        // the SDK's own attribute is removed first.
        $request = $request->withoutAttribute(StreamableHttpTransport::PRINCIPAL_ATTRIBUTE);
        if ($principalAttribute !== null) {
            $principal = self::principal($request->getAttribute($principalAttribute), $this->settings->principalClaim);
            if ($principal !== null) {
                $request = $request->withAttribute(StreamableHttpTransport::PRINCIPAL_ATTRIBUTE, $principal);
            }
        }

        $transport = new StreamableHttpTransport(
            $request,
            $this->responseFactory,
            $this->streamFactory,
            $this->logger,
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
     * a verified token (an object or array, as auth-jwks and auth-jwt set it). Anything else is no principal, and a
     * request with no principal cannot use a session.
     */
    private static function principal(mixed $value, string $claim): ?string
    {
        if (is_object($value)) {
            $value = $value->{$claim} ?? null;
        } elseif (is_array($value)) {
            $value = $value[$claim] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
