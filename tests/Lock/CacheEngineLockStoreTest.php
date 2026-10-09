<?php

namespace Tests\Lock;

use ByJG\Resilience\Lock\CacheEngineLockStore;
use ByJG\Resilience\Lock\LockStoreInterface;
use ByJG\Resilience\Mutex;
use Tests\Support\EngineFactory;
use Tests\Support\RecordingCasEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CacheEngineLockStoreTest extends TestCase
{
    private const KEY = 'resilience-test-lock';

    public static function engineProvider(): array
    {
        return [
            'redis' => [EngineFactory::REDIS],
            'memcached' => [EngineFactory::MEMCACHED],
        ];
    }

    /**
     * Skips rather than fails when the docker-compose services are not running, so the suite stays
     * usable without them. The forked test below is the one that actually proves the guarantee.
     */
    private function storeOrSkip(string $engineName): CacheEngineLockStore
    {
        $engine = EngineFactory::make($engineName);
        if (!$engine->isAvailable()) {
            $this->markTestSkipped("$engineName is not available");
        }

        $engine->delete(self::KEY);

        return new CacheEngineLockStore($engine);
    }

    public function testAdapterIsALockStore(): void
    {
        $this->assertInstanceOf(LockStoreInterface::class, new CacheEngineLockStore(new RecordingCasEngine()));
    }

    /**
     * The adapter's only job is to forward, so the thing worth asserting is that it forwards the
     * key, the token and the TTL untouched onto the matching compare-and-swap primitive.
     */
    public function testEachOperationMapsOntoItsCompareAndSwapPrimitive(): void
    {
        $engine = new RecordingCasEngine();
        $store = new CacheEngineLockStore($engine);

        $this->assertTrue($store->acquireIfAbsent('the-key', 'the-token', 30));
        $this->assertTrue($store->refreshIfOwner('the-key', 'the-token', 45));
        $this->assertTrue($store->releaseIfOwner('the-key', 'the-token'));

        $this->assertSame([
            ['method' => 'setIfAbsent', 'key' => 'the-key', 'value' => 'the-token', 'ttl' => 30],
            ['method' => 'expireIfEquals', 'key' => 'the-key', 'value' => 'the-token', 'ttl' => 45],
            ['method' => 'deleteIfEquals', 'key' => 'the-key', 'value' => 'the-token', 'ttl' => null],
        ], $engine->calls);
    }

    public function testTheAdapterRejectsAnEngineWithoutCompareAndSwap(): void
    {
        $this->expectException(\TypeError::class);

        /** @psalm-suppress InvalidArgument Deliberately passing an engine that cannot make the guarantee. */
        new CacheEngineLockStore(new \ByJG\Cache\Psr16\FileSystemCacheEngine());
    }

    #[DataProvider('engineProvider')]
    public function testOnlyOneCallerAcquires(string $engineName): void
    {
        $store = $this->storeOrSkip($engineName);

        $this->assertTrue($store->acquireIfAbsent(self::KEY, 'token-a', 30));
        $this->assertFalse($store->acquireIfAbsent(self::KEY, 'token-b', 30));

        $this->assertTrue($store->releaseIfOwner(self::KEY, 'token-a'));
        $this->assertTrue($store->acquireIfAbsent(self::KEY, 'token-b', 30));
        $store->releaseIfOwner(self::KEY, 'token-b');
    }

    #[DataProvider('engineProvider')]
    public function testReleaseAndRefreshAreRejectedForANonOwner(string $engineName): void
    {
        $store = $this->storeOrSkip($engineName);

        $store->acquireIfAbsent(self::KEY, 'token-a', 30);

        $this->assertFalse($store->releaseIfOwner(self::KEY, 'token-b'));
        $this->assertFalse($store->refreshIfOwner(self::KEY, 'token-b', 30));
        $this->assertFalse($store->acquireIfAbsent(self::KEY, 'token-c', 30), 'token-a must still hold it');

        $store->releaseIfOwner(self::KEY, 'token-a');
    }

    /**
     * The case the whole design exists for: an owner whose TTL lapsed must not be able to release
     * or extend the lock that has since passed to somebody else.
     */
    #[DataProvider('engineProvider')]
    public function testAnExpiredOwnerCannotTouchTheNewOwnersLock(string $engineName): void
    {
        $store = $this->storeOrSkip($engineName);

        $store->acquireIfAbsent(self::KEY, 'stale-owner', 1);
        sleep(2);
        $this->assertTrue($store->acquireIfAbsent(self::KEY, 'new-owner', 30));

        $this->assertFalse($store->releaseIfOwner(self::KEY, 'stale-owner'));
        $this->assertFalse($store->refreshIfOwner(self::KEY, 'stale-owner', 30));
        $this->assertFalse($store->acquireIfAbsent(self::KEY, 'third', 30));

        $store->releaseIfOwner(self::KEY, 'new-owner');
    }

    #[DataProvider('engineProvider')]
    public function testRefreshKeepsTheLockAliveBeyondItsOriginalTtl(string $engineName): void
    {
        $store = $this->storeOrSkip($engineName);

        $store->acquireIfAbsent(self::KEY, 'token-a', 1);
        $this->assertTrue($store->refreshIfOwner(self::KEY, 'token-a', 30));

        sleep(2);

        $this->assertFalse($store->acquireIfAbsent(self::KEY, 'token-b', 30), 'The refresh must have taken effect');
        $store->releaseIfOwner(self::KEY, 'token-a');
    }

    #[DataProvider('engineProvider')]
    public function testMutexRunsAndReleasesThroughTheAdapter(string $engineName): void
    {
        $store = $this->storeOrSkip($engineName);
        $mutex = new Mutex($store);

        $ran = false;
        $result = $mutex->execute('job', function () use (&$ran) {
            $ran = true;
            return 42;
        }, ttlSeconds: 30);

        $this->assertTrue($ran);
        $this->assertSame(42, $result);

        // Released in the finally block, so the lock is free again.
        $token = $mutex->tryAcquire('job', 30);
        $this->assertIsString($token);
        $mutex->release('job', $token);
    }

    /**
     * Sequential assertions cannot demonstrate mutual exclusion - the naive PSR-16 store passes
     * every test above. Twenty real processes racing for the same Mutex is what separates them.
     */
    #[DataProvider('engineProvider')]
    public function testExactlyOneOfManyProcessesHoldsTheMutex(string $engineName): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to test concurrent access');
        }

        $this->storeOrSkip($engineName);
        (EngineFactory::make($engineName))->delete('resilience:mutex:contended');

        $children = 20;
        $startAt = microtime(true) + 0.5;
        $pids = [];

        for ($i = 0; $i < $children; $i++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'Could not fork');

            if ($pid === 0) {
                $won = false;
                try {
                    usleep((int)max(0.0, ($startAt - microtime(true)) * 1_000_000.0));
                    // Reconnect after the fork: a socket inherited from the parent would be shared
                    // by every child, and the resulting corruption would masquerade as a race.
                    $mutex = new Mutex(new CacheEngineLockStore(EngineFactory::make($engineName)));
                    $won = is_string($mutex->tryAcquire('contended', 60));
                } finally {
                    exit($won ? 0 : 1);
                }
            }

            $pids[] = $pid;
        }

        $winners = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                $winners++;
            }
        }

        $this->assertSame(1, $winners, "Expected exactly one holder of the mutex, got $winners");

        (EngineFactory::make($engineName))->delete('resilience:mutex:contended');
    }
}
