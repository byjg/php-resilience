<?php

namespace ByJG\Resilience\Lock;

/**
 * The minimum contract a backing store must satisfy to be a correct distributed lock.
 *
 * This is deliberately NOT PSR-16: PSR-16 has no set-if-absent primitive, so a lock built on it
 * can hand the same key to two callers no matter how carefully it is written. Implement this
 * interface on top of a store that offers a real atomic primitive — Redis `SET key val NX PX` or
 * Memcached `add()` — and the Mutex becomes sound.
 *
 * {@see CacheEngineLockStore} does exactly that for byjg/cache-engine engines.
 *
 * Implementations MUST honour the following:
 *  - acquireIfAbsent() is atomic: given N concurrent callers on the same key, exactly one returns true.
 *  - The TTL is applied in the SAME atomic step as the write. A store that writes the value first
 *    and sets the expiry afterwards can leave a permanent lock if the process dies in between.
 *  - releaseIfOwner()/refreshIfOwner() only act when the stored value equals $token, so a caller
 *    whose lock already expired can never release or extend a lock another owner has taken.
 */
interface LockStoreInterface
{
    /**
     * Atomically store $token under $key only if $key is currently absent.
     *
     * @param string $key        Fully qualified key (the Mutex has already applied its prefix).
     * @param string $token      Unique value identifying the caller as the lock owner.
     * @param int $ttlSeconds    Lifetime of the lock; MUST be applied atomically with the write.
     * @return bool True if this caller now owns the lock; false if it was already held.
     */
    public function acquireIfAbsent(string $key, string $token, int $ttlSeconds): bool;

    /**
     * Release the lock only if $token is still the stored owner.
     *
     * @return bool True if this caller owned the lock and it was removed; false otherwise.
     */
    public function releaseIfOwner(string $key, string $token): bool;

    /**
     * Extend the lock TTL only if $token is still the stored owner.
     *
     * @return bool True if this caller owned the lock and the TTL was extended; false otherwise.
     */
    public function refreshIfOwner(string $key, string $token, int $ttlSeconds): bool;
}
