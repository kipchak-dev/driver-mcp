<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Library;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs a warning when the SDK's DNS rebinding protection refuses a request, naming what was refused and the setting
 * that decides it. The SDK answers 403 with a fixed body and logs nothing, so without this a first deployment that
 * has not set MCP_HOST fails with no explanation on the server.
 *
 * The response is passed on unchanged: the caller never sees the allowed list. The Host and Origin headers come from
 * the caller, so they are logged escaped and shortened, and cannot add lines to the log.
 */
final readonly class RefusalLogging implements MiddlewareInterface
{
    /** The bodies of DnsRebindingProtectionMiddleware's refusals in mcp/sdk 0.10.0 (lines 68 and 76). */
    public const string HOST_REFUSED = 'Forbidden: Invalid Host header.';
    public const string ORIGIN_REFUSED = 'Forbidden: Invalid Origin header.';

    public const int MAX_LOGGED_LENGTH = 255;

    public function __construct(private string $server, private LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if ($response->getStatusCode() !== 403) {
            return $response;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $text = $body->getContents();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        if ($text === self::HOST_REFUSED) {
            $this->logger->warning(sprintf(
                "MCP server '%s' refused a request for Host '%s' with 403: that host is not in 'allowed_hosts' in kipchak.mcp. Add it there, or set MCP_HOST.",
                $this->server,
                self::escape($request->getHeaderLine('Host')),
            ));
        } elseif ($text === self::ORIGIN_REFUSED) {
            $this->logger->warning(sprintf(
                "MCP server '%s' refused a request from Origin '%s' with 403: that origin is not in 'allowed_origins' in kipchak.mcp. Add it there if browsers on that origin should reach this server.",
                $this->server,
                self::escape($request->getHeaderLine('Origin')),
            ));
        }

        return $response;
    }

    /**
     * Control characters (including newlines and the C1 range) become \xNN, and the result is cut to
     * MAX_LOGGED_LENGTH characters, so a header cannot forge a second log line or flood the log.
     */
    public static function escape(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $escaped = preg_replace_callback(
            '/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/',
            static fn (array $m): string => implode('', array_map(static fn (string $byte): string => sprintf('\\x%02X', ord($byte)), str_split($m[0]))),
            $value,
        ) ?? '';

        return mb_strlen($escaped) > self::MAX_LOGGED_LENGTH
            ? mb_substr($escaped, 0, self::MAX_LOGGED_LENGTH - 3) . '...'
            : $escaped;
    }
}
