# Changelog - Version 7.0

## Overview

First release of `byjg/php-resilience`. There is no earlier version to migrate from — the version
number starts at 7.0 to line up with [byjg/cache-engine](https://github.com/byjg/php-cache-engine)
7.0, which supplies the atomic primitive the `Mutex` is built on.

The library covers two problems that show up whenever work is spread across processes: something is
not ready yet, and something must happen only once.

## Requirements

| | |
|---|---|
| PHP | `>=8.3 <8.7` — 8.3, 8.4, 8.5 and 8.6 |
| Required | `psr/simple-cache ^3.0` |
| Optional | `byjg/cache-engine ^7.0` — needed by `CacheEngineLockStore`, and therefore by any real use of `Mutex` |

## What's Included

### ExponentialBackoffWaiter

Polls an operation on a growing delay until it succeeds or a total budget is spent.

```php
$waiter = new ExponentialBackoffWaiter(
    initialDelayMicros: 200_000,
    maxTotalWaitMicros: 3_500_000,
    multiplier: 2.0
);

$reward = $waiter->wait(fn() => $rewardRepo->get($id));
```

`maxTotalWaitMicros` is a ceiling on elapsed wall-clock time, not merely on when the loop decides to
stop. Each sleep is trimmed against the remaining budget, so the default configuration sleeps
200 / 400 / 800 / 1600 / **500** ms and returns at exactly 3.5 s. An implementation that let the last
delay run its full 3200 ms would return after 6.2 s — nearly double what the caller asked for.

`sleepFn` and `timeFn` are injectable, so a suite covering a multi-second policy runs instantly on a
virtual clock.

### Mutex

A named lock with an owner token and a TTL for crash recovery. `tryAcquire` (non-blocking),
`acquire` (blocking, via the waiter above), `release`, `refresh`, and an `execute` helper that
releases in a `finally` block.

```php
$mutex = new Mutex(new CacheEngineLockStore(new RedisCacheEngine()));

$result = $mutex->execute('rebuild-report', fn() => rebuildTheReport(), ttlSeconds: 60);
```

### LockStoreInterface and CacheEngineLockStore

The lock is only as sound as its store, so the store is its own contract: `acquireIfAbsent` must be
atomic, the TTL must be applied in the same step as the write, and release/refresh must act only on
a token match.

`CacheEngineLockStore` adapts any `byjg/cache-engine` engine implementing `CompareAndSwapInterface`
— currently `RedisCacheEngine` and `MemcachedEngine`.

### Attributes

`#[ExponentialBackoff]` and `#[Mutex]`, applied through `ExponentialBackoffExecutor` and
`MutexExecutor`. Attributes are metadata: calling the method directly bypasses them entirely, and
the executor is what reads and honours them.

## Design Decisions Worth Knowing

### A plain PSR-16 cache is not accepted by `Mutex`

`Mutex::__construct()` takes a `LockStoreInterface` and nothing else. PSR-16 has no atomic
set-if-absent operation, so a lock built on `has()` then `set()` lets two processes both find the key
absent and both write their token — the second wins, the first believes it holds a lock it does not.
Reading the value back narrows the window without closing it.

An earlier draft shipped such a store and documented the caveat. Documenting a hazard is weaker than
removing it, and the constructor signature made the unsafe path the shortest one to write. The
type system now rejects it instead.

### The `Mutex` is not a general-purpose lock manager

Correct acquisition gets you one thing: at most one holder at a time. It does not give you fairness,
reentrancy, or protection against a TTL that lapses mid-operation — `releaseIfOwner` will correctly
refuse the stale release, but by then the work has run twice. See
[Lock Stores](docs/lock-stores.md#what-this-does-not-guarantee).

### `ExponentialBackoffWaiter` is a poller, not a retry policy

It reacts to a **returned value** failing a predicate. If the operation **throws**, the exception
propagates immediately with no retry. That is the right shape for "the record has not been written
yet" and the wrong shape for "the HTTP call returned 503", which needs to know which exceptions are
retryable and which will fail identically forever.

Retry-on-exception, jitter, and circuit breaking are not part of this release.

## Documentation

- [Exponential Backoff](docs/exponential-backoff.md)
- [Mutex](docs/mutex.md)
- [Lock Stores](docs/lock-stores.md)
- [Attributes](docs/attributes.md)

## Testing

```bash
docker compose up -d
vendor/bin/phpunit
docker compose down
```

Tests needing Redis or Memcached skip cleanly when the services are absent, and read
`REDIS_SERVER` / `MEMCACHED_SERVER` so CI can point them at service containers.

Mutual exclusion is verified by forking twenty processes at a synchronised barrier and asserting
exactly one acquires the lock. Sequential assertions cannot distinguish a correct lock from a racy
one — a naive implementation passes every single-process test that can be written for it.

## Platform and Toolchain

- **PHP** widened to `>=8.3 <8.7`. The previous `<8.6` is exclusive and excluded PHP 8.6.
- **PHPUnit** pinned to `^12.5`. PHPUnit 13 requires PHP `>=8.4.1`, which breaks the 8.3 floor,
  and needs `sebastian/diff ^9.0`, which stable Psalm 6.16.1 rejects.
- **Psalm** moved out of `require-dev` into `tools/psalm/composer.json`. Psalm enumerates the PHP
  versions it supports and no release lists 8.6, so as a dev dependency it made `composer install`
  fail on the 8.6 build before any test ran. `composer psalm` bootstraps the tool and runs it.
- **CI** now builds on 8.6, and Psalm runs in its own job on 8.5 rather than inside the build matrix.
- `phpunit.xml.dist` renamed to `phpunit.xml`; `psalm.xml` gained `cacheDirectory="/tmp/psalm"`,
  matching the other components.

## Links

- [Documentation](https://github.com/byjg/php-resilience/tree/master/docs)
- [Report Issues](https://github.com/byjg/php-resilience/issues)
