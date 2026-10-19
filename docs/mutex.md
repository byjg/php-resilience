---
sidebar_position: 2
---

# Mutex

A named lock that only one process holds at a time. Each acquisition mints a random token, and
every subsequent operation is checked against that token, so a caller can never release or extend a
lock that has passed to somebody else.

```php
use ByJG\Cache\Psr16\RedisCacheEngine;
use ByJG\Resilience\Lock\CacheEngineLockStore;
use ByJG\Resilience\Mutex;

$mutex = new Mutex(new CacheEngineLockStore(new RedisCacheEngine()));

$result = $mutex->execute('rebuild-report', fn() => rebuildTheReport(), ttlSeconds: 60);
```

The constructor takes a `LockStoreInterface` and nothing else — see [Lock Stores](lock-stores.md)
for what qualifies and why a plain PSR-16 cache does not.

```php
public function __construct(LockStoreInterface $store, string $prefix = 'resilience:mutex:')
```

Every key is written as `$prefix . $name`, so locks stay in their own namespace and cannot collide
with ordinary cached values. Change `$prefix` if several applications share one store.

## execute

The one to reach for. Acquires, runs the callback, and releases in a `finally` block, so the lock
is freed even if the callback throws:

```php
$result = $mutex->execute('rebuild-report', function () {
    // critical section
    return 'done';
}, ttlSeconds: 60);
```

It blocks while waiting, and throws `TimeoutException` if the lock never becomes free within the
backoff window. Pass a custom waiter as the fourth argument to control that window:

```php
use ByJG\Resilience\ExponentialBackoffWaiter;

$mutex->execute(
    'rebuild-report',
    fn() => rebuildTheReport(),
    ttlSeconds: 60,
    waiter: new ExponentialBackoffWaiter(maxTotalWaitMicros: 10_000_000)   // wait up to 10s
);
```

## tryAcquire

Non-blocking. Returns the owner token, or `null` if somebody else holds the lock. Use it when
losing the race means "skip this run" rather than "wait your turn":

```php
$token = $mutex->tryAcquire('nightly-import', 300);
if ($token === null) {
    return;   // another worker already has it
}

try {
    runTheImport();
} finally {
    $mutex->release('nightly-import', $token);
}
```

Always release in a `finally`. Without it, a thrown exception leaves the lock held until its TTL
expires, and everyone else stalls for the remainder.

## acquire

Blocking. Polls with an [exponential backoff](exponential-backoff.md) until the lock frees up:

```php
public function acquire(
    string $name,
    int $ttlSeconds = 30,
    ?ExponentialBackoffWaiter $waiter = null,
    bool $throwOnTimeout = true
): ?string
```

With `throwOnTimeout: false` it returns `null` instead of throwing when the wait is exhausted,
which is the right choice when carrying on without the lock is acceptable.

## release

Frees the lock, but only if `$token` is still the recorded owner:

```php
$mutex->release('nightly-import', $token);   // false if you no longer own it
```

A `false` return is worth noticing rather than ignoring: it means your TTL lapsed while you were
working and somebody else has since taken the lock. Your critical section was not exclusive for its
whole duration.

## refresh

Extends the TTL of a lock you still own. For work whose duration you cannot predict, this is safer
than picking a generously long TTL up front — a long TTL is also how long everyone waits if your
process dies holding it.

```php
$token = $mutex->acquire('long-migration', 30);

foreach ($batches as $batch) {
    process($batch);

    if (!$mutex->refresh('long-migration', $token, 30)) {
        // The lock is no longer ours. Stop - somebody else is doing this work now.
        break;
    }
}

$mutex->release('long-migration', $token);
```

## Choosing a TTL

The TTL exists so a crashed process cannot lock a resource forever. It is a recovery mechanism, not
a work estimate, and it forces a trade-off in both directions:

- **Too short** and the lock expires mid-operation. Nothing tells you at the time; you find out when
  `release()` returns `false`, long after a second worker started the same job.
- **Too long** and a process that dies holding the lock blocks everyone for the full remainder.

Set it to comfortably longer than the work should take, and use `refresh()` for anything open-ended.
See [what a Mutex does not guarantee](lock-stores.md#what-this-does-not-guarantee).

## Also available

Methods can be guarded declaratively with `#[Mutex]` — see [Attributes](attributes.md).
