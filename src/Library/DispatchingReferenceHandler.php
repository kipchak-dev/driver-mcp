<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Library;

use Kipchak\Driver\Mcp\Grant;
use Kipchak\Driver\Mcp\ToolCall;
use Kipchak\Driver\Mcp\ToolDefinition;
use Kipchak\Driver\Mcp\ToolDispatcher;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Capability\Registry\ElementReference;
use Mcp\Server\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Server\Capability\Registry\ToolReference;
use Mcp\Server\Session\SessionInterface;

/**
 * Sends every tool call through the server's ToolDispatcher. Resources and prompts go straight to the SDK's handler.
 * The SDK calls this from CallToolHandler, which turns any exception other than a ToolCallException into an internal
 * error with no data.
 */
final readonly class DispatchingReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private ReferenceHandlerInterface $inner,
        private ToolDispatcher $dispatcher,
    ) {
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        if (!$reference instanceof ToolReference) {
            return $this->inner->handle($reference, $arguments);
        }

        $session = $arguments['_session'] ?? null;
        $token = $session instanceof SessionInterface ? $session->get(AccessToken::class) : null;
        $caller = null;
        if ($token instanceof AccessToken) {
            $caller = array_filter(
                $token->getClaims(),
                static fn (mixed $value, string $key): bool => in_array($key, ['iss', 'sub', 'client_id'], true) && is_string($value),
                ARRAY_FILTER_USE_BOTH,
            );
            if (!isset($caller['sub'])) {
                $caller = null;
            }
        }

        $public = array_filter($arguments, static fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        $called = false;
        $next = function (mixed $grant = null) use ($reference, $arguments, $session, &$called): mixed {
            if ($called) {
                throw new \LogicException("The tool dispatcher ran tool '{$reference->tool->name}' twice in one call.");
            }
            $called = true;

            if (!$session instanceof SessionInterface) {
                if ($grant !== null) {
                    throw new \LogicException('The tool dispatcher gave a grant, but the request has no session to carry it.');
                }

                return $this->inner->handle($reference, $arguments);
            }

            // Set for this call only, whatever was there before, and removed when the tool returns or throws.
            $session->forget(Grant::SESSION_KEY);
            if ($grant !== null) {
                $session->set(Grant::SESSION_KEY, $grant);
            }
            try {
                return $this->inner->handle($reference, $arguments);
            } finally {
                $session->forget(Grant::SESSION_KEY);
            }
        };

        return $this->dispatcher->dispatch(new ToolCall(ToolDefinition::fromReference($reference), $public, $caller), $next);
    }
}
