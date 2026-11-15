<?php

declare(strict_types=1);

namespace Kipchak\Driver\Mcp\Tests\Fake;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /** @return list<string> */
    public function messages(string $level): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['message'],
            array_filter($this->records, static fn (array $r): bool => $r['level'] === $level),
        ));
    }
}
