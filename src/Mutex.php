<?php

namespace ByJG\Resilience;

use ByJG\Resilience\Exceptions\TimeoutException;
use ByJG\Resilience\Lock\LockStoreInterface;

/**
 * A Mutex backed by a {@see LockStoreInterface}.
 *
 * The mutex is exactly as sound as the store it is given, which is why the constructor accepts
 * nothing else. A plain PSR-16 cache is deliberately NOT accepted: PSR-16 offers no atomic
 * set-if-absent, so a mutex built on it can hand the same lock to two callers, and an API that
 * takes one would make that the easiest thing to write.
 *
 * Use {@see \ByJG\Resilience\Lock\CacheEngineLockStore} with a byjg/cache-engine engine that
 * implements CompareAndSwapInterface, or implement {@see LockStoreInterface} over whatever atomic
 * primitive your store provides.
 *
 * The mutex stores a unique token as the value; release/refresh only proceed if the caller token matches.
 */
class Mutex
{
    private LockStoreInterface $store;
    private string $prefix;

    public function __construct(LockStoreInterface $store, string $prefix = 'resilience:mutex:')
    {
        $this->store = $store;
        $this->prefix = $prefix;
    }

    private function key(string $name): string
    {
        return $this->prefix . $name;
    }

    /**
     * Try to acquire the mutex without waiting.
     *
     * @param string $name
     * @param int $ttlSeconds Lock TTL in seconds
     * @return string|null A unique token that identifies the lock owner if acquired; null otherwise.
     */
    public function tryAcquire(string $name, int $ttlSeconds = 30): ?string
    {
        // Generate a random token for this lock owner
        $token = bin2hex(random_bytes(16));

        return $this->store->acquireIfAbsent($this->key($name), $token, $ttlSeconds) ? $token : null;
    }

    /**
     * Acquire the mutex waiting up to the waiter/timeout settings.
     *
     * @param string $name
     * @param int $ttlSeconds Lock TTL in seconds
     * @param ExponentialBackoffWaiter|null $waiter Optional waiter; if null, a default is created.
     * @param bool $throwOnTimeout Whether to throw TimeoutException on timeout; when false returns null.
     * @return string|null The owner token if acquired; null if not acquired and $throwOnTimeout=false.
     * @throws TimeoutException When not acquired within time and $throwOnTimeout=true.
     */
    public function acquire(
        string $name,
        int $ttlSeconds = 30,
        ?ExponentialBackoffWaiter $waiter = null,
        bool $throwOnTimeout = true
    ): ?string {
        $waiter = $waiter ?? new ExponentialBackoffWaiter();

        $token = $waiter->wait(
            operation: function () use ($name, $ttlSeconds) {
                return $this->tryAcquire($name, $ttlSeconds);
            },
            isSuccess: static fn($v) => is_string($v) && $v !== '',
            throwOnTimeout: $throwOnTimeout
        );

        return is_string($token) ? $token : null;
    }

    /**
     * Release the mutex if the provided token matches the current owner token.
     *
     * @param string $name
     * @param string $token The owner token returned by acquire/tryAcquire
     * @return bool True if the lock was released; false otherwise (e.g., not the owner or already expired)
     */
    public function release(string $name, string $token): bool
    {
        return $this->store->releaseIfOwner($this->key($name), $token);
    }

    /**
     * Refresh/extend the lock TTL if the token matches the owner; useful for long-running operations.
     *
     * @param string $name
     * @param string $token
     * @param int $ttlSeconds New TTL in seconds
     * @return bool True on success, false if not owner or failed to set
     */
    public function refresh(string $name, string $token, int $ttlSeconds): bool
    {
        return $this->store->refreshIfOwner($this->key($name), $token, $ttlSeconds);
    }

    /**
     * Convenience helper: acquire the lock, execute the callback, then release in a finally block.
     *
     * @template T
     * @param string $name
     * @param callable():T $callback
     * @param int $ttlSeconds
     * @param ExponentialBackoffWaiter|null $waiter
     * @return T
     */
    public function execute(string $name, callable $callback, int $ttlSeconds = 30, ?ExponentialBackoffWaiter $waiter = null)
    {
        $token = $this->acquire($name, $ttlSeconds, $waiter, true);
        try {
            return $callback();
        } finally {
            if (is_string($token)) {
                $this->release($name, $token);
            }
        }
    }
}
