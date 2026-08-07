<?php

namespace ByJG\Resilience\Tests;

use ByJG\Resilience\ExponentialBackoffWaiter;
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
}
