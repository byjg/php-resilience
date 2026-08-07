<?php

namespace ByJG\Resilience\Tests;

use ByJG\Resilience\Attributes\ExponentialBackoff;
use ByJG\Resilience\ExponentialBackoffExecutor;
use ByJG\Resilience\ExponentialBackoffWaiter;
use ByJG\Resilience\Jitter;
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

    /**
     * Deliberately tiny real delays: these two never succeed, so they run to the full budget on a
     * real clock. 20ms keeps the suite fast while leaving room for jitter to visibly vary.
     */
    #[ExponentialBackoff(
        initialDelayMicros: 1_000,
        maxTotalWaitMicros: 20_000,
        multiplier: 2.0,
        throwOnTimeout: false
    )]
    public function neverSucceeds(): ?string
    {
        $this->calls++;
        return null;
    }

    #[ExponentialBackoff(
        initialDelayMicros: 1_000,
        maxTotalWaitMicros: 20_000,
        multiplier: 2.0,
        throwOnTimeout: false,
        jitter: Jitter::Full
    )]
    public function neverSucceedsJittered(): ?string
    {
        $this->calls++;
        return null;
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

    /**
     * Control for the test below: with no jitter the sleep schedule is fixed, so the same budget
     * always allows exactly the same number of attempts.
     */
    public function testWithoutJitterTheAttemptCountIsDeterministic(): void
    {
        $counts = [];
        for ($run = 0; $run < 10; $run++) {
            $svc = new DummyService();
            ExponentialBackoffExecutor::callWithAttributes($svc, 'neverSucceeds');
            $counts[] = $svc->calls;
        }

        $this->assertCount(1, array_unique($counts), 'An unjittered backoff must be reproducible');
    }

    /**
     * The attribute's jitter setting has to reach the waiter the executor builds. It did not:
     * the executor constructed the waiter in two places and neither forwarded it, so jitter was
     * unreachable from the attribute API entirely while looking perfectly configured.
     *
     * Randomised sleeps consume the budget unevenly, so the attempt count varies between runs -
     * which is only true if the setting actually arrived.
     */
    public function testJitterOnTheAttributeReachesTheWaiter(): void
    {
        $counts = [];
        for ($run = 0; $run < 20; $run++) {
            $svc = new DummyService();
            ExponentialBackoffExecutor::callWithAttributes($svc, 'neverSucceedsJittered');
            $counts[] = $svc->calls;
        }

        $this->assertGreaterThan(
            1,
            count(array_unique($counts)),
            'Every run made the same number of attempts, so the attribute jitter never reached the waiter'
        );
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
