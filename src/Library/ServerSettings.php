<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Library;

use Kipchak\Driver\Mcp\Exception\ConfigurationException;
use Mcp\Server\Transport\StreamableHttpTransport;

/**
 * One server's settings from config/kipchak.mcp.php, checked once. Every problem is reported with the server's name
 * and the key, and nothing here talks to the network or the container.
 */
final readonly class ServerSettings
{
    /**
     * @param list<class-string> $tools
     * @param list<string>       $discovery
     * @param list<string>       $allowedHosts
     * @param list<string>       $allowedOrigins
     */
    private function __construct(
        public string $server,
        public string $name,
        public string $version,
        public ?string $instructions,
        public array $tools,
        public array $discovery,
        public string $basePath,
        public ?string $sessions,
        public int $sessionTtl,
        public array $allowedHosts,
        public array $allowedOrigins,
        public ?string $principalAttribute,
        public string $principalClaim,
        public int $maxBodyBytes,
        /** @var list<string> */
        public array $requestStateKeys = [],
        public int $requestStateTtl = 600,
        /** @var class-string<\Kipchak\Driver\Mcp\ToolDispatcher>|null */
        public ?string $toolDispatcher = null,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(string $server, array $config, string $defaultBasePath): self
    {
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $server) !== 1) {
            throw ConfigurationException::for($server, 'servers', 'has a server name that is not letters, digits, ".", "_" or "-".');
        }

        $name = self::nonEmptyString($server, $config, 'name');
        $version = self::nonEmptyString($server, $config, 'version');

        $instructions = $config['instructions'] ?? null;
        if ($instructions !== null && (!is_string($instructions) || trim($instructions) === '')) {
            throw ConfigurationException::for($server, 'instructions', 'must be a non-empty string or null.');
        }

        $tools = self::stringList($server, $config, 'tools');
        foreach ($tools as $class) {
            if (!class_exists($class)) {
                throw ConfigurationException::for($server, 'tools', "names the class {$class}, which does not exist (check the name and the autoloader).");
            }
        }
        if (count(array_unique($tools)) !== count($tools)) {
            throw ConfigurationException::for($server, 'tools', 'lists a class more than once.');
        }

        $discovery = self::stringList($server, $config, 'discovery');
        $basePath = $config['base_path'] ?? $defaultBasePath;
        if (!is_string($basePath) || $basePath === '') {
            throw ConfigurationException::for($server, 'base_path', 'must be a directory path.');
        }
        foreach ($discovery as $directory) {
            if (!is_dir(rtrim($basePath, '/') . '/' . ltrim($directory, '/'))) {
                throw ConfigurationException::for($server, 'discovery', "names {$directory}, which is not a directory under {$basePath}.");
            }
        }

        $sessions = $config['sessions'] ?? null;
        if ($sessions !== null && (!is_string($sessions) || $sessions === '')) {
            throw ConfigurationException::for($server, 'sessions', 'must be null (no sessions) or the container key of a PSR-16 cache, such as \'drivers.cache.valkey.cache\'.');
        }

        $sessionTtl = $config['session_ttl'] ?? 3600;
        if (!is_int($sessionTtl) || $sessionTtl < 60) {
            throw ConfigurationException::for($server, 'session_ttl', 'must be a whole number of seconds, at least 60.');
        }

        $allowedHosts = self::stringList($server, $config, 'allowed_hosts');
        if ($allowedHosts === []) {
            throw ConfigurationException::for($server, 'allowed_hosts', 'is empty, so every request would be refused with 403. List the host names clients use, without the port (for example the value of MCP_HOST).');
        }
        foreach ($allowedHosts as $host) {
            if (str_contains($host, '/') || (str_contains($host, ':') && !str_starts_with($host, '['))) {
                throw ConfigurationException::for($server, 'allowed_hosts', "contains '{$host}'. Give host names only, without a scheme, path or port.");
            }
        }

        $allowedOrigins = self::stringList($server, $config, 'allowed_origins');

        $principalAttribute = $config['principal_attribute'] ?? null;
        if ($principalAttribute !== null && (!is_string($principalAttribute) || $principalAttribute === '')) {
            throw ConfigurationException::for($server, 'principal_attribute', 'must be null or the name of a request attribute set by an authentication middleware.');
        }
        if ($principalAttribute === StreamableHttpTransport::PRINCIPAL_ATTRIBUTE) {
            throw ConfigurationException::for($server, 'principal_attribute', 'is the SDK\'s own attribute. Name the attribute your authentication middleware sets.');
        }

        $principalClaim = $config['principal_claim'] ?? 'sub';
        if (!is_string($principalClaim) || $principalClaim === '') {
            throw ConfigurationException::for($server, 'principal_claim', 'must name the claim that identifies the caller, such as "sub".');
        }

        // A session the SDK cannot bind to a caller can be used by anyone who learns its id. With sessions on, the
        // principal must come from the authentication middleware in front of the route.
        if ($sessions !== null && $principalAttribute === null) {
            throw ConfigurationException::for($server, 'principal_attribute', 'must be set when \'sessions\' is set, so that each session belongs to the caller who opened it. Name the request attribute your authentication middleware sets, or set \'sessions\' to null.');
        }

        $maxBodyBytes = $config['max_body_bytes'] ?? StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES;
        if (!is_int($maxBodyBytes) || $maxBodyBytes < 1024) {
            throw ConfigurationException::for($server, 'max_body_bytes', 'must be a whole number of bytes, at least 1024.');
        }

        // The keys that sign the state a 2026-07-28 tool hands back when it asks the user for input. A key is never
        // shown in a message, only its position and length.
        $requestState = $config['request_state'] ?? [];
        if (!is_array($requestState)) {
            throw ConfigurationException::for($server, 'request_state', 'must be an array with \'keys\' and \'ttl\'.');
        }
        $requestStateKeys = $requestState['keys'] ?? [];
        if (!is_array($requestStateKeys) || !array_is_list($requestStateKeys)) {
            throw ConfigurationException::for($server, 'request_state.keys', 'must be a list of keys.');
        }
        foreach ($requestStateKeys as $position => $key) {
            if (!is_string($key) || strlen($key) < 32) {
                throw ConfigurationException::for($server, 'request_state.keys', 'has key ' . ($position + 1) . ' of ' . (is_string($key) ? strlen($key) . ' bytes' : get_debug_type($key)) . '; each key must be a string of at least 32 bytes.');
            }
        }
        $requestStateTtl = $requestState['ttl'] ?? 600;
        if (!is_int($requestStateTtl) || $requestStateTtl < 1) {
            throw ConfigurationException::for($server, 'request_state.ttl', 'must be a whole number of seconds, at least 1.');
        }

        $toolDispatcher = $config['tool_dispatcher'] ?? null;
        if ($toolDispatcher !== null) {
            if (!is_string($toolDispatcher) || !class_exists($toolDispatcher)) {
                throw ConfigurationException::for($server, 'tool_dispatcher', 'must be null or the name of a class that implements ' . \Kipchak\Driver\Mcp\ToolDispatcher::class . '.');
            }
            if (!is_subclass_of($toolDispatcher, \Kipchak\Driver\Mcp\ToolDispatcher::class)) {
                throw ConfigurationException::for($server, 'tool_dispatcher', "names {$toolDispatcher}, which does not implement " . \Kipchak\Driver\Mcp\ToolDispatcher::class . '.');
            }
        }

        return new self(
            $server,
            $name,
            $version,
            $instructions,
            $tools,
            $discovery,
            $basePath,
            $sessions,
            $sessionTtl,
            $allowedHosts,
            $allowedOrigins,
            $principalAttribute,
            $principalClaim,
            $maxBodyBytes,
            $requestStateKeys,
            $requestStateTtl,
            $toolDispatcher,
        );
    }

    public function offersNothing(): bool
    {
        return $this->tools === [] && $this->discovery === [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function nonEmptyString(string $server, array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw ConfigurationException::for($server, $key, 'must be a non-empty string.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function stringList(string $server, array $config, string $key): array
    {
        $value = $config[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw ConfigurationException::for($server, $key, 'must be a list.');
        }
        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw ConfigurationException::for($server, $key, 'must hold only non-empty strings.');
            }
        }

        return $value;
    }
}
