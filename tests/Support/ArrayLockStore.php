<?php

namespace Tests\Support;

use ByJG\Resilience\Lock\LockStoreInterface;

/**
 * A single-process LockStoreInterface used to prove the Mutex talks to the store contract only.
 * Single-threaded PHP gives it the atomicity the interface demands; it records every key it was
 * handed so tests can assert the Mutex applied its prefix before delegating.
 *
 * @phpstan-type Entry array{token:string, expiresAt:float}
 */
class ArrayLockStore implements LockStoreInterface
{
    /** @var array<string, array{token:string, expiresAt:float}> */
    private array $locks = [];

    /** @var list<string> Every key this store was asked about, in call order. */
    public array $seenKeys = [];

    /** @var callable(): float */
    private $nowFn;

    /** @param callable():float|null $nowFn */
    public function __construct(?callable $nowFn = null)
    {
        $this->nowFn = $nowFn ?? static fn(): float => microtime(true);
    }

    /** Who holds $key right now, or null when it is free. Lets tests assert without mutating. */
    public function owner(string $key): ?string
    {
        if (!isset($this->locks[$key])) {
            return null;
        }
        if ($this->locks[$key]['expiresAt'] <= ($this->nowFn)()) {
            unset($this->locks[$key]);
            return null;
        }
        return $this->locks[$key]['token'];
    }

    #[\Override]
    public function acquireIfAbsent(string $key, string $token, int $ttlSeconds): bool
    {
        $this->seenKeys[] = $key;
        if ($this->owner($key) !== null) {
            return false;
        }
        $this->locks[$key] = ['token' => $token, 'expiresAt' => ($this->nowFn)() + (float) $ttlSeconds];
        return true;
    }

    #[\Override]
    public function releaseIfOwner(string $key, string $token): bool
    {
        $this->seenKeys[] = $key;
        if ($this->owner($key) !== $token) {
            return false;
        }
        unset($this->locks[$key]);
        return true;
    }

    #[\Override]
    public function refreshIfOwner(string $key, string $token, int $ttlSeconds): bool
    {
        $this->seenKeys[] = $key;
        if ($this->owner($key) !== $token) {
            return false;
        }
        $this->locks[$key]['expiresAt'] = ($this->nowFn)() + (float) $ttlSeconds;
        return true;
    }
}
