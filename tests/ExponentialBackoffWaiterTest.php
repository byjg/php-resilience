<?php

namespace ByJG\Resilience\Tests;

use ByJG\Resilience\ExponentialBackoffWaiter;
use ByJG\Resilience\Jitter;
use ByJG\Resilience\Exceptions\TimeoutException;
use PHPUnit\Framework\TestCase;

class ExponentialBackoffWaiterTest extends TestCase
{
    public function testReturnsWhenOperationEventuallySucceeds(): void
    {
        $calls = 0;
        $sleeps = [];
        $now = 1000.0; // virtual time in seconds

        $sleepFn = function (int $micros) use (&$sleeps, &$now): void {
            $sleeps[] = $micros;
            $now += (float)$micros / 1_000_000.0; // advance virtual time
        };
        $timeFn = function () use (&$now): float { return $now; };

        $waiter = new ExponentialBackoffWaiter(
            initialDelayMicros: 200_000,
            maxTotalWaitMicros: 3_500_000,
            multiplier: 2.0,
            maxDelayMicros: null,
            sleepFn: $sleepFn,
            timeFn: $timeFn
        );

        $operation = function () use (&$calls) {
            $calls++;
            if ($calls < 4) { // fail 3 times, succeed on 4th
                return null;
            }
            return 'OK';
        };

        $result = $waiter->wait($operation);

        $this->assertSame('OK', $result);
        $this->assertSame(4, $calls, 'Should have attempted 4 times');
        // Expected sleeps: 200ms, 400ms, 800ms before success
        $this->assertSame([200_000, 400_000, 800_000], $sleeps);
    }

    public function testTimeoutThrows(): void
    {
        $calls = 0;
        $now = 2000.0;
        $sleeps = [];

        $sleepFn = function (int $micros) use (&$sleeps, &$now): void {
            $sleeps[] = $micros;
            $now += (float)$micros / 1_000_000.0;
        };
        $timeFn = function () use (&$now): float { return $now; };

        $waiter = new ExponentialBackoffWaiter(
            initialDelayMicros: 200_000,
            maxTotalWaitMicros: 1_000_000, // 1s total
            multiplier: 2.0,
            maxDelayMicros: null,
            sleepFn: $sleepFn,
            timeFn: $timeFn
        );

        $operation = function () use (&$calls) {
            $calls++;
            return null; // never succeeds
        };

        $this->expectException(TimeoutException::class);
        try {
            $waiter->wait($operation, null, true);
        } finally {
            $this->assertGreaterThanOrEqual(2, count($sleeps));
            $this->assertSame(1 + count($sleeps), $calls, 'Calls should be sleeps + first immediate attempt');
        }
    }

    public function testCustomPredicate(): void
    {
        $calls = 0;
        $now = 3000.0;

        $sleepFn = function (int $micros) use (&$now): void {
            $now += (float)$micros / 1_000_000.0;
        };
        $timeFn = function () use (&$now): float { return $now; };

        $waiter = new ExponentialBackoffWaiter(
            initialDelayMicros: 100_000,
            maxTotalWaitMicros: 500_000,
            multiplier: 2.0,
            sleepFn: $sleepFn,
            timeFn: $timeFn
        );

        $operation = function () use (&$calls) {
            $calls++;
            return $calls * 3; // 3, 6, 9, 12...
        };

        $result = $waiter->wait($operation, fn(int $v) => $v >= 10);
        $this->assertSame(12, $result);
    }

    public function testNeverSleepsPastTheTotalWaitDeadline(): void
    {
        $start = 5000.0;
        $now = $start;
        $sleeps = [];
        $sleepFn = function (int $micros) use (&$sleeps, &$now): void {
            $sleeps[] = $micros;
            $now += (float)$micros / 1_000_000.0;
        };
        $timeFn = function () use (&$now): float { return $now; };

        $waiter = new ExponentialBackoffWaiter(
            initialDelayMicros: 200_000,
            maxTotalWaitMicros: 3_500_000,
            multiplier: 2.0,
            maxDelayMicros: null,
            sleepFn: $sleepFn,
            timeFn: $timeFn
        );

        try {
            $waiter->wait(fn() => null);
            $this->fail('Expected TimeoutException');
        } catch (TimeoutException) {
            // expected
        }

        // The delay sequence would reach 200+400+800+1600+3200 = 6.2s unclamped; the last
        // sleep must be trimmed so the total never exceeds the declared budget.
        $this->assertSame(3_500_000, array_sum($sleeps));
        $this->assertSame($start + 3.5, $now);
        $this->assertSame([200_000, 400_000, 800_000, 1_600_000, 500_000], $sleeps);
    }

    public function testMaxDelayMicrosCapsEveryIndividualSleep(): void
    {
        $now = 6000.0;
        $sleeps = [];
        $sleepFn = function (int $micros) use (&$sleeps, &$now): void {
            $sleeps[] = $micros;
            $now += (float)$micros / 1_000_000.0;
        };
        $timeFn = function () use (&$now): float { return $now; };

        $waiter = new ExponentialBackoffWaiter(
            initialDelayMicros: 400_000,
            maxTotalWaitMicros: 2_000_000,
            multiplier: 3.0,
            maxDelayMicros: 500_000,
            sleepFn: $sleepFn,
            timeFn: $timeFn
        );

        $waiter->wait(fn() => null, null, false);

        $this->assertSame([400_000, 500_000, 500_000, 500_000, 100_000], $sleeps);
        $this->assertLessThanOrEqual(500_000, max($sleeps));
    }

    public function testReturnLastOnTimeout(): void
    {
        $now = 4000.0;
        $sleepFn = function (int $micros) use (&$now): void { $now += (float)$micros / 1_000_000.0; };
        $timeFn = function () use (&$now): float { return $now; };

        $waiter = new ExponentialBackoffWaiter(
            initialDelayMicros: 150_000,
            maxTotalWaitMicros: 200_000, // very small
            multiplier: 2.0,
            sleepFn: $sleepFn,
            timeFn: $timeFn
        );

        $value = $waiter->wait(fn() => null, null, false);
        $this->assertNull($value);
    }

    public function testJitterIsOffByDefaultSoTimingIsUnchanged(): void
    {
        $sleeps = $this->runWaiter(Jitter::None, null);

        // Identical to the sequence asserted in testNeverSleepsPastTheTotalWaitDeadline.
        $this->assertSame([200_000, 400_000, 800_000, 1_600_000, 500_000], $sleeps);
    }

    public function testFullJitterCanShortenAnySleepToTheFloor(): void
    {
        // A random source that always returns the minimum draws the shortest legal sleep every
        // time. A small budget keeps the run short, since every sleep is now one microsecond.
        $sleeps = $this->runWaiter(Jitter::Full, fn(int $min, int $max): int => $min, 1_000);

        // Never zero: a zero-length sleep would retry immediately and burst the dependency.
        $this->assertSame(array_fill(0, 1_000, 1), $sleeps);
        $this->assertSame(1_000, array_sum($sleeps), 'The budget still bounds the total wait');
    }

    public function testFullJitterAtItsMaximumMatchesTheUnjitteredSeries(): void
    {
        // Always drawing the maximum makes full jitter degenerate to the deterministic backoff,
        // which shows the growth series itself is untouched by jitter.
        $sleeps = $this->runWaiter(Jitter::Full, fn(int $min, int $max): int => $max);

        $this->assertSame([200_000, 400_000, 800_000, 1_600_000, 500_000], $sleeps);
    }

    public function testEqualJitterAlwaysWaitsAtLeastHalfTheDelay(): void
    {
        $sleeps = $this->runWaiter(Jitter::Equal, fn(int $min, int $max): int => $min);

        // Worst case for equal jitter is exactly half of each computed delay, with the last sleep
        // trimmed to whatever remained of the 3.5s budget.
        $this->assertSame([100_000, 200_000, 400_000, 800_000, 1_600_000, 400_000], $sleeps);
        $this->assertSame(3_500_000, array_sum($sleeps));
    }

    public function testEqualJitterAtItsMaximumMatchesTheUnjitteredSeries(): void
    {
        $sleeps = $this->runWaiter(Jitter::Equal, fn(int $min, int $max): int => $max);

        $this->assertSame([200_000, 400_000, 800_000, 1_600_000, 500_000], $sleeps);
    }

    public function testJitterNeverBreachesTheTotalDeadline(): void
    {
        // Real randomness, many runs: the deadline clamp must hold whatever is drawn.
        foreach ([Jitter::Full, Jitter::Equal] as $jitter) {
            for ($run = 0; $run < 50; $run++) {
                $sleeps = $this->runWaiter($jitter, null);

                $this->assertSame(
                    3_500_000,
                    array_sum($sleeps),
                    'Jitter must not let the total wait exceed the declared budget'
                );
            }
        }
    }

    public function testJitteredSleepsStayWithinTheirDeterministicBound(): void
    {
        // The series is 200k, 400k, 800k, 1.6M, ... capped by the 3.5s budget, so no sleep may
        // exceed the delay for its position. Real randomness, asserted against that invariant
        // rather than a fixed sequence. The sleep count varies per run, so the bound is computed.
        for ($run = 0; $run < 50; $run++) {
            $sleeps = $this->runWaiter(Jitter::Full, null);

            foreach ($sleeps as $i => $slept) {
                $bound = min(200_000 * (2 ** (int)$i), 3_500_000);

                $this->assertGreaterThanOrEqual(1, $slept);
                $this->assertLessThanOrEqual($bound, $slept, "Sleep $i exceeded its series bound");
            }
        }
    }

    public function testFullJitterActuallyVariesBetweenRuns(): void
    {
        // Guards against the enum being wired up but the random source never consulted: 30 runs of
        // a genuinely random full jitter cannot all produce the identical first sleep.
        $firstSleeps = [];
        for ($run = 0; $run < 30; $run++) {
            $firstSleeps[] = $this->runWaiter(Jitter::Full, null)[0];
        }

        $this->assertGreaterThan(1, count(array_unique($firstSleeps)), 'Jitter produced no variation at all');
    }

    /**
     * Runs a waiter that never succeeds, on a virtual clock, and returns the sleeps it took.
     *
     * @param callable(int,int):int|null $randomFn
     * @return int[]
     */
    private function runWaiter(Jitter $jitter, ?callable $randomFn, int $maxTotalWaitMicros = 3_500_000): array
    {
        $now = 7000.0;
        $sleeps = [];

        $waiter = new ExponentialBackoffWaiter(
            initialDelayMicros: 200_000,
            maxTotalWaitMicros: $maxTotalWaitMicros,
            multiplier: 2.0,
            maxDelayMicros: null,
            sleepFn: function (int $micros) use (&$sleeps, &$now): void {
                $sleeps[] = $micros;
                $now += (float)$micros / 1_000_000.0;
            },
            timeFn: function () use (&$now): float {
                return $now;
            },
            jitter: $jitter,
            randomFn: $randomFn
        );

        $waiter->wait(fn() => null, null, false);

        return $sleeps;
    }
}
