<?php

namespace ByJG\Resilience\Tests;

use ByJG\Resilience\Attributes\ExponentialBackoff;
use ByJG\Resilience\ExponentialBackoffExecutor;
use ByJG\Resilience\ExponentialBackoffWaiter;
use PHPUnit\Framework\TestCase;

class DummyService
{
    public int $calls = 0;

    #[ExponentialBackoff(initialDelayMicros: 200_000, maxTotalWaitMicros: 3_500_000, multiplier: 2.0)]
    public function fetchValue(): ?string
    {
        $this->calls++;
        if ($this->calls < 4) {
            return null;
        }
        return 'OK';
    }
}

class ExponentialBackoffAttributeTest extends TestCase
{
    public function testAttributeDrivenExecution(): void
    {
        $now = 1000.0;
        $sleeps = [];
        $sleepFn = function (int $micros) use (&$sleeps, &$now): void {
            $sleeps[] = $micros;
            $now += (float)$micros / 1_000_000.0;
        };
        $timeFn = function () use (&$now): float { return $now; };

        $waiterOverride = new ExponentialBackoffWaiter(
            initialDelayMicros: 200_000,
            maxTotalWaitMicros: 3_500_000,
            multiplier: 2.0,
            maxDelayMicros: null,
            sleepFn: $sleepFn,
            timeFn: $timeFn
        );

        $svc = new DummyService();
        $result = ExponentialBackoffExecutor::callWithAttributes($svc, 'fetchValue', waiterOverride: $waiterOverride);

        $this->assertSame('OK', $result);
        $this->assertSame(4, $svc->calls);
        $this->assertSame([200_000, 400_000, 800_000], $sleeps);
    }

    public function testNoAttributeFallsBackToDirectCall(): void
    {
        $svc = new class {
            public int $calls = 0;
            public function fetchNoAttr(): ?string { $this->calls++; return 'X'; }
        };

        $result = ExponentialBackoffExecutor::callWithAttributes($svc, 'fetchNoAttr');
        $this->assertSame('X', $result);
        $this->assertSame(1, $svc->calls);
    }
}
