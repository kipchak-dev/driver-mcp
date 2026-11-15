<?php

/**
 * routes/mcp.php: the MCP endpoint behind kipchak/middleware-auth-jwks. Every request needs a valid access token
 * carrying the mcp scope, and each session belongs to the token's issuer and subject.
 *
 * In config/kipchak.mcp.php, set:
 *
 *     'principal_attribute' => \Kipchak\Middleware\Auth\JWKS\Handlers\AuthJWKS::ATTRIBUTE_TOKEN,
 *     'principal_claim' => 'sub',
 *
 * @var \Kipchak\Core\Components\App $app
 */

use Kipchak\Driver\Mcp\Mcp;
use Kipchak\Middleware\Auth\JWKS\Handlers\AuthJWKS;

$app->any('/mcp', Mcp::endpoint('default'))
    ->add(new AuthJWKS($app->getContainer(), ['mcp']));
