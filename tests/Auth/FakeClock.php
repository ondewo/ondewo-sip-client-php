<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Auth;

/** A monotonic clock the test moves by hand; starts at 1000 like the shared test catalogue. */
final class FakeClock
{
    public function __construct(public float $now = 1000.0)
    {
    }

    public function __invoke(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
