# MCP Driver for Kipchak

A Model Context Protocol (MCP) driver for Kipchak: serve tools, resources and prompts from a Kipchak API, so that
an AI assistant can use the API's operations. Built on the official PHP SDK, [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk).

* **Attributes on ordinary classes**: mark a public method with `#[McpTool]`, `#[McpResource]`,
  `#[McpResourceTemplate]` or `#[McpPrompt]`. Input schemas come from the parameter types and descriptions from the
  doc block, exactly as in the SDK.
* **Services from the Kipchak container**: tool classes are built by the Kipchak container, so a constructor can
  ask for a Doctrine entity manager, a cache or any other service.
* **Both protocol eras on one endpoint**: clients on the handshake revisions (up to 2025-11-25) and on 2026-07-28,
  which needs no session, are served by the same route.
* **No sessions by default**: each request stands alone, so the API needs no shared store however many copies of
  it run. Sessions, when you want them, live in a PSR-16 cache from another Kipchak driver and belong to the caller
  who opened them.
* **Authentication stays where it is**: the endpoint sits behind your route's authentication middleware, and the
  driver passes that middleware's caller identity to the SDK.
* **Worker mode**: the server is built once per FrankenPHP worker, and each request runs with a transport of its
  own, so nothing about one caller reaches the next request.
* **Checked at boot**: a configuration error stops the API when it starts, with a message naming the server and
  the setting.

#### Composer Package

`kipchak/driver-mcp`

## Installation

```bash
composer require kipchak/driver-mcp
```

Or, with the Kipchak CLI, `kipchak add mcp`, which also copies the configuration and initialises the driver.

The SDK requires `php-http/discovery`, which is a Composer plugin. Kipchak already provides the PSR-17 factories
the SDK needs (from `slim/psr7`), and the driver passes them to the SDK, so the plugin has nothing to install. Allow
it explicitly, so that a non-interactive install does not stop to ask:

```json
"config": {
    "allow-plugins": {
        "php-http/discovery": false
    }
}
```

Initialise the driver in `drivers/drivers.php`, after the Config and Logger drivers, and after any cache driver
whose cache keeps the sessions:

```php
\Kipchak\Driver\Mcp\Mcp::initialise($container);
```

Copy `sample.config.php` to `config/kipchak.mcp.php`.

## A first tool

```php
<?php

namespace Api\Mcp;

use Mcp\Server\Capability\Attribute\McpTool;

final class StatusTools
{
    /**
     * Reports whether the API is running.
     */
    #[McpTool(name: 'status')]
    public function status(): array
    {
        return ['status' => 'ok'];
    }
}
```

List the class in `config/kipchak.mcp.php` under `'tools'`, and route the endpoint in a file under `routes/`:

```php
$app->any('/mcp', \Kipchak\Driver\Mcp\Mcp::endpoint('default'));
```

Route every method (`any`), not only `POST`.

**A tool that reads or changes data must sit behind authentication.** Put the route behind your authentication
middleware and set `'principal_attribute'` to the request attribute that middleware sets. The documentation shows
the route behind `kipchak/middleware-auth-jwks`.

## Host names

The endpoint refuses, with 403, any request whose `Host` is not in `'allowed_hosts'`. This protects a server on a
private network from DNS rebinding. Set `MCP_HOST` to the host name clients use when you deploy. A refused request
is logged as a warning that names the host received.

## Tool dispatcher

An API whose tools read customer data needs one place that decides who may call each tool. Name a class implementing
`Kipchak\Driver\Mcp\ToolDispatcher` in `tool_dispatcher`. The Kipchak container builds it.

- `accept(ToolDefinition $tool)` is called for every tool when the API starts, including tools found by discovery. It
  reads the attributes on `$tool->function` and throws to refuse a tool, which stops the API.
- `dispatch(ToolCall $call, \Closure $next)` runs every tool call. `$call->caller` is the caller's issuer, client and
  subject, or `null` for an anonymous call. Call `$next()` to run the tool, or `$next($grant)` to hand it what you
  authorised, which the tool reads with `Grant::from($context)` from a `RequestContext` parameter. Throw an
  `Mcp\Server\Exception\ToolCallException` to refuse with a message. Any other exception closes the call with no data.

Resources and prompts do not pass through the dispatcher.

## The SDK version

The driver requires `mcp/sdk` at exactly `0.10.0`. The SDK is before version 1.0, and each of its minor releases from 0.5
to 0.10 changed its interfaces, so each SDK release reaches you in a driver release that has passed this driver's tests.
When the SDK publishes a security release, a driver release taking it follows within five working days.

A project that also requires `mcp/sdk` directly must require the same version as the driver.

## Configuration

See [`sample.config.php`](sample.config.php) and https://kipchak.dev/docs/driver-mcp.

## Licence

MIT. The SDK, `mcp/sdk`, is licensed under the Apache License 2.0; it is a dependency and is not copied into this
package.
