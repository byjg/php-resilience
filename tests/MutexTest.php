<?php

namespace ByJG\Resilience\Tests;

use ByJG\Resilience\ExponentialBackoffWaiter;
use ByJG\Resilience\Mutex;
use ByJG\Resilience\Tests\Support\ArrayLockStore;
use PHPUnit\Framework\TestCase;

class MutexTest extends TestCase
{
    public function testTryAcquireAndRelease(): void
    {
        $mutex = new Mutex(new ArrayLockStore());

        $token = $mutex->tryAcquire('job', 5);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Second acquire should fail while held
        $token2 = $mutex->tryAcquire('job', 5);
        $this->assertNull($token2);

        // Release and try again
        $released = $mutex->release('job', $token);
        $this->assertTrue($released);

        $token3 = $mutex->tryAcquire('job', 5);
        $this->assertIsString($token3);
        $this->assertNotSame($token, $token3);
    }

    public function testAcquireWaitsUntilLockExpires(): void
    {
        $now = 1000.0;
        $timeFn = function () use (&$now): float { return $now; };
        $sleeps = [];
        $sleepFn = function (int $micros) use (&$now, &$sleeps): void {
            $sleeps[] = $micros;
            $now += (float)$micros / 1_000_000.0;
        };

        $store = new ArrayLockStore($timeFn);
        $mutex = new Mutex($store);

        // Pre-set a lock owned by someone else with TTL of 1 second
        $store->acquireIfAbsent('resilience:mutex:job', 'other-token', 1);

        $waiter = new ExponentialBackoffWaiter(
            initialDelayMicros: 200_000,
            maxTotalWaitMicros: 2_500_000,
            multiplier: 2.0,
            maxDelayMicros: null,
            sleepFn: $sleepFn,
            timeFn: $timeFn
        );

        // Should wait ~1s then acquire
        $token = $mutex->acquire('job', ttlSeconds: 5, waiter: $waiter, throwOnTimeout: true);
        $this->assertIsString($token);
        $this->assertGreaterThanOrEqual(1_000_000, array_sum($sleeps));
    }

    public function testReleaseOnlyByOwner(): void
    {
        $store = new ArrayLockStore();
        $mutex = new Mutex($store);
        $store->acquireIfAbsent('resilience:mutex:doc', 'owner-a', 10);

        $releasedWrong = $mutex->release('doc', 'owner-b');
        $this->assertFalse($releasedWrong);

        $releasedRight = $mutex->release('doc', 'owner-a');
        $this->assertTrue($releasedRight);
    }

    public function testExecuteConvenience(): void
    {
        $store = new ArrayLockStore();
        $mutex = new Mutex($store);

        $ran = false;
        $result = $mutex->execute('task', function () use (&$ran) {
            $ran = true;
            return 42;
        }, ttlSeconds: 5);

        $this->assertTrue($ran);
        $this->assertSame(42, $result);
        // After execute, lock should be gone
        $this->assertNull($store->owner('resilience:mutex:task'));
    }

    public function testAcceptsAnyLockStoreAndPassesThePrefixedKey(): void
    {
        $store = new ArrayLockStore();
        $mutex = new Mutex($store, prefix: 'app:lock:');

        $token = $mutex->tryAcquire('invoice', 30);
        $this->assertIsString($token);
        $this->assertNull($mutex->tryAcquire('invoice', 30), 'Store must refuse the second acquire');

        $this->assertTrue($mutex->refresh('invoice', $token, 60));
        $this->assertFalse($mutex->refresh('invoice', 'someone-else', 60));
        $this->assertTrue($mutex->release('invoice', $token));

        $this->assertSame(
            ['app:lock:invoice'],
            array_values(array_unique($store->seenKeys)),
            'The Mutex must apply its prefix before delegating to the store'
        );
    }

    public function testRefreshExtendsTheLockLifetime(): void
    {
        $now = 500.0;
        $nowFn = function () use (&$now): float {
            return $now;
        };
        $mutex = new Mutex(new ArrayLockStore($nowFn));

        $token = $mutex->tryAcquire('report', 10);
        $this->assertIsString($token);

        $now += 9.0;
        $this->assertTrue($mutex->refresh('report', $token, 10));

        $now += 5.0; // past the original expiry, inside the refreshed one
        $this->assertNull($mutex->tryAcquire('report', 10), 'Refreshed lock must still be held');
    }
}
