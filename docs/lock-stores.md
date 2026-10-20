---
sidebar_position: 3
---

# Lock Stores

A [Mutex](mutex.md) is exactly as sound as the store behind it, so the store is its own abstraction
with its own contract:

```php
interface LockStoreInterface
{
    public function acquireIfAbsent(string $key, string $token, int $ttlSeconds): bool;
    public function releaseIfOwner(string $key, string $token): bool;
    public function refreshIfOwner(string $key, string $token, int $ttlSeconds): bool;
}
```

Implementations must honour three things:

1. **`acquireIfAbsent()` is atomic.** Given any number of concurrent callers on the same free key,
   exactly one returns `true`.
2. **The TTL is applied in the same step as the write.** A store that writes the value and sets the
   expiry as two operations leaves a lock nobody can release if the process dies in between.
3. **Release and refresh act only on a token match**, so a caller whose lock already expired cannot
   disturb the lock somebody else now holds.

## CacheEngineLockStore

The one that ships with the library. It adapts any `byjg/cache-engine` engine implementing
`CompareAndSwapInterface`:

```php
use ByJG\Cache\Psr16\RedisCacheEngine;
use ByJG\Resilience\Lock\CacheEngineLockStore;
use ByJG\Resilience\Mutex;

$mutex = new Mutex(new CacheEngineLockStore(new RedisCacheEngine()));
```

| Engine | Supported | Underlying primitive |
|---|---|---|
| `RedisCacheEngine` | yes | `SET key token NX EX`, plus Lua for the token-guarded release and refresh |
| `MemcachedEngine` | yes | native `add()`, plus `cas()` |
| `FileSystemCacheEngine` | **no** | — |

The constructor takes `CompareAndSwapInterface` rather than a cache engine, so an engine that cannot
make the guarantee is rejected by the type system rather than at runtime:

```php
new CacheEngineLockStore(new FileSystemCacheEngine());   // TypeError
```

`FileSystemCacheEngine` is excluded deliberately. `flock()` attaches to an inode rather than a path,
and the engine deletes files by path, so one process can hold a lock on an unlinked file while
another creates and locks a fresh file at the same path — two holders, two inodes, both convinced
they are exclusive. On network file systems `flock()` is weaker still.

Add the dependency with:

```bash
composer require byjg/cache-engine
```

## Why a plain PSR-16 cache is not accepted

PSR-16 has no atomic set-if-absent operation. Anything built on it has to check and then write:

```php
if (!$cache->has($key)) {   // <- another process can write here
    $cache->set($key, $token, $ttl);
}
```

Two processes can both find the key absent and both write their token. The second wins; the first
walks away believing it holds a lock it does not. Reading the value back afterwards narrows the
window but cannot close it — there is no interleaving-free path through those methods.

Since `Mutex::__construct()` accepts only a `LockStoreInterface`, the broken version is not
something you can reach for by accident.

## Writing your own

If your store is not a cache-engine engine, implement the interface over whatever atomic primitive
it does offer. For a raw `\Redis` client:

```php
use ByJG\Resilience\Lock\LockStoreInterface;

class RedisLockStore implements LockStoreInterface
{
    public function __construct(private \Redis $redis) {}

    #[\Override]
    public function acquireIfAbsent(string $key, string $token, int $ttlSeconds): bool
    {
        // SET key token NX PX ttl - value and expiry land in one atomic step
        return (bool) $this->redis->set($key, $token, ['nx', 'px' => $ttlSeconds * 1000]);
    }

    #[\Override]
    public function releaseIfOwner(string $key, string $token): bool
    {
        $lua = 'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end';
        return (bool) $this->redis->eval($lua, [$key, $token], 1);
    }

    #[\Override]
    public function refreshIfOwner(string $key, string $token, int $ttlSeconds): bool
    {
        $lua = 'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("pexpire", KEYS[1], ARGV[2]) else return 0 end';
        return (bool) $this->redis->eval($lua, [$key, $token, $ttlSeconds * 1000], 1);
    }
}
```

Note that release and refresh both need Lua. `GET` followed by `DEL` from PHP is two round trips
with a gap in between, which is the very thing the token check is supposed to prevent.

If you write one, test it with concurrent processes rather than sequential assertions. A store with
a race in it passes every single-process test you can write; forking twenty children at a
synchronised barrier and asserting exactly one winner is what actually distinguishes them.

## What this does not guarantee

A correct `acquireIfAbsent()` gives you one thing: at most one holder at a time. Everything else you
might want from a lock, it does not provide.

- **No fairness.** Waiters poll on a backoff and whoever happens to ask at the right moment wins. A
  process can lose repeatedly while newer arrivals succeed.
- **No reentrancy.** Calling `tryAcquire()` for a name you already hold returns `null`. Nested
  acquisitions of the same lock deadlock against themselves until the TTL expires.
- **The TTL is still a guess.** If your work outlives it, the lock is released while you are still
  running and a second worker can start. `releaseIfOwner()` will correctly refuse your stale
  release — but by then the work has already run twice. Use
  [`refresh()`](mutex.md#refresh) for open-ended work.
- **No protection against clock skew or partitions.** These are single-node locks. A store that
  fails over to a replica can lose the key entirely, handing the lock to a second holder. If
  double-execution would be genuinely unacceptable, a lock is the wrong last line of defence —
  make the operation idempotent as well.
