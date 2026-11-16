<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp;

use Mcp\Server\RequestContext;

/**
 * What the tool dispatcher authorised for one call, such as the organisation a tool may read. The dispatcher passes it
 * to $next($grant); the tool reads it with Grant::from($context), declaring a RequestContext parameter, which the SDK
 * injects and keeps out of the input schema. It lives in the request's session only while the tool runs, and a caller
 * cannot set it.
 */
final class Grant
{
    public const SESSION_KEY = 'kipchak.mcp.grant';

    private function __construct()
    {
    }

    public static function from(RequestContext $context): mixed
    {
        return $context->getSession()->get(self::SESSION_KEY);
    }
}
