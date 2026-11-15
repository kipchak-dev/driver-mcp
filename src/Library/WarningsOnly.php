<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Library;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Passes warnings and worse to the Kipchak logger and drops the rest. The SDK's discoverer reports every scan at info
 * level; at boot that would be written to standard output, where the Kipchak CLI expects only its own JSON.
 */
final class WarningsOnly extends AbstractLogger
{
    private const array PASSED = [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if (in_array($level, self::PASSED, true)) {
            $this->logger->log($level, $message, $context);
        }
    }
}
