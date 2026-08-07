<?php

namespace ByJG\Resilience\Tests;

use ByJG\Resilience\Attributes\Mutex as MutexAttribute;
use ByJG\Resilience\ExponentialBackoffWaiter;
use ByJG\Resilience\Mutex;
use ByJG\Resilience\MutexExecutor;
use ByJG\Resilience\Tests\Support\ArrayLockStore;
use PHPUnit\Framework\TestCase;

class ServiceWithMutex
{
    public int $calls = 0;

    #[MutexAttribute(name: 'svc-job', ttlSeconds: 2, throwOnTimeout: true)]
    public function doWork(): string
    {
        $this->calls++;
        return 'DONE';
    }
}

class MutexAttributeTest extends TestCase
{
    public function testAttributeDrivenMutexExecution(): void
    {
        $now = 1000.0;
        $timeFn = function () use (&$now): float { return $now; };
        $sleeps = [];
        $sleepFn = function (int $micros) use (&$now, &$sleeps): void { $sleeps[] = $micros; $now += (float)$micros / 1_000_000.0; };

        $store = new ArrayLockStore($timeFn);
        $mutex = new Mutex($store);
        $waiter = new ExponentialBackoffWaiter(initialDelayMicros: 200_000, maxTotalWaitMicros: 2_000_000, multiplier: 2.0, sleepFn: $sleepFn, timeFn: $timeFn);

        $svc = new ServiceWithMutex();
        $result = MutexExecutor::callWithAttributes($svc, 'doWork', mutex: $mutex, waiterOverride: $waiter);

        $this->assertSame('DONE', $result);
        $this->assertSame(1, $svc->calls);
        // After execution, lock must be released
        $this->assertNull($store->owner('resilience:mutex:svc-job'));
    }

    public function testMutexAttributeTimeoutReturnsNullWhenConfigured(): void
    {
        $now = 2000.0;
        $timeFn = function () use (&$now): float { return $now; };
        $sleeps = [];
        $sleepFn = function (int $micros) use (&$now, &$sleeps): void { $sleeps[] = $micros; $now += (float)$micros / 1_000_000.0; };

        $store = new ArrayLockStore($timeFn);
        $mutex = new Mutex($store);

        // Pre-acquire lock by someone else with TTL=10 seconds
        $store->acquireIfAbsent('resilience:mutex:svc-job', 'other-owner', 10);

        // Service that doesn't matter, we want to confirm it is not called when timeout returns null
        $svc = new class {
            public int $calls = 0;
            #[MutexAttribute(name: 'svc-job', ttlSeconds: 2, throwOnTimeout: false)]
            public function doNotRun(): string { $this->calls++; return 'X'; }
        };

        $waiter = new ExponentialBackoffWaiter(initialDelayMicros: 200_000, maxTotalWaitMicros: 600_000, multiplier: 2.0, sleepFn: $sleepFn, timeFn: $timeFn);

        $result = MutexExecutor::callWithAttributes($svc, 'doNotRun', mutex: $mutex, waiterOverride: $waiter);

        $this->assertNull($result);
        $this->assertSame(0, $svc->calls, 'Method should not have been executed if lock not acquired');
    }
}
