<?php

namespace ByJG\Resilience\Lock;

use ByJG\Cache\CompareAndSwapInterface;

/**
 * Lock store backed by a byjg/cache-engine engine that supports compare-and-swap.
 *
 * Every operation here maps onto a primitive the storage engine resolves in a single indivisible
 * step, so there is no window between checking and writing for a competing process to slip into.
 *
 * Not every engine qualifies - RedisCacheEngine and MemcachedEngine do, FileSystemCacheEngine
 * deliberately does not, because flock() binds to an inode while the engine deletes files by path.
 * The constructor takes CompareAndSwapInterface rather than a cache engine so an engine that cannot
 * make the guarantee cannot be passed in at all.
 *
 * ```php
 * use ByJG\Cache\Psr16\RedisCacheEngine;
 * use ByJG\Resilience\Lock\CacheEngineLockStore;
 * use ByJG\Resilience\Mutex;
 *
 * $mutex = new Mutex(new CacheEngineLockStore(new RedisCacheEngine()));
 * ```
 */
class CacheEngineLockStore implements LockStoreInterface
{
    private CompareAndSwapInterface $engine;

    public function __construct(CompareAndSwapInterface $engine)
    {
        $this->engine = $engine;
    }

    #[\Override]
    public function acquireIfAbsent(string $key, string $token, int $ttlSeconds): bool
    {
        return $this->engine->setIfAbsent($key, $token, $ttlSeconds);
    }

    #[\Override]
    public function releaseIfOwner(string $key, string $token): bool
    {
        return $this->engine->deleteIfEquals($key, $token);
    }

    #[\Override]
    public function refreshIfOwner(string $key, string $token, int $ttlSeconds): bool
    {
        return $this->engine->expireIfEquals($key, $token, $ttlSeconds);
    }
}
