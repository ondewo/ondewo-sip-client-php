<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Auth;

use Psr\Log\AbstractLogger;
use Stringable;

/** A PSR-3 logger that keeps every record as [level, message]. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message];
    }

    /** @return list<string> the messages logged at `$level` */
    public function messages(string $level): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record[1],
            array_filter($this->records, static fn (array $record): bool => $record[0] === $level),
        ));
    }

    /** Every message, one per line. */
    public function text(): string
    {
        return implode("\n", array_map(static fn (array $record): string => $record[1], $this->records));
    }
}
