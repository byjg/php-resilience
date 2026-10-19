---
sidebar_position: 4
---

# Attributes

Both features can be attached declaratively, keeping the retry or locking policy next to the method
it governs instead of at every call site.

**PHP attributes do not change how a method runs on their own.** They are metadata; something has to
read them. That something is the matching executor, and calling the method directly bypasses the
attribute entirely:

```php
$svc->fetch();                                                  // plain call, attribute ignored
ExponentialBackoffExecutor::callWithAttributes($svc, 'fetch');  // attribute honoured
```

A method with no attribute is invoked directly by the executor, so routing calls through one is
always safe.

## #[ExponentialBackoff]

```php
use ByJG\Resilience\Attributes\ExponentialBackoff;
use ByJG\Resilience\ExponentialBackoffExecutor;

class ReportService
{
    #[ExponentialBackoff(initialDelayMicros: 200_000, maxTotalWaitMicros: 3_500_000, multiplier: 2.0)]
    public function fetch(string $id): ?string
    {
        return $this->repo->get($id);   // null until it is ready
    }
}

$svc = new ReportService();
$result = ExponentialBackoffExecutor::callWithAttributes($svc, 'fetch', args: ['report-42']);
```

Parameters mirror the [waiter](exponential-backoff.md#constructor): `initialDelayMicros`,
`maxTotalWaitMicros`, `multiplier`, `maxDelayMicros`, `throwOnTimeout`, and `jitter`.

```php
#[ExponentialBackoff(maxTotalWaitMicros: 3_500_000, jitter: Jitter::Full)]
public function fetch(string $id): ?string { /* ... */ }
```

The waiter's `randomFn` has no attribute equivalent — attribute arguments must be constant
expressions, and a callable cannot be written as one. The default `random_int` is what production
wants; a test needing deterministic jitter passes a fully configured waiter via `waiterOverride`.

Success is judged by the same default rule — the return value is not `null`. Supply a predicate
through the executor when that is not the right test:

```php
ExponentialBackoffExecutor::callWithAttributes(
    $svc,
    'fetch',
    args: ['report-42'],
    isSuccess: fn($v) => $v !== null && $v !== ''
);
```

## #[Mutex]

```php
use ByJG\Cache\Psr16\RedisCacheEngine;
use ByJG\Resilience\Attributes\Mutex as MutexAttr;
use ByJG\Resilience\Lock\CacheEngineLockStore;
use ByJG\Resilience\Mutex;
use ByJG\Resilience\MutexExecutor;

class JobService
{
    #[MutexAttr(name: 'job:rebuild-cache', ttlSeconds: 10, throwOnTimeout: true)]
    public function rebuildCache(): string
    {
        // critical section
        return 'OK';
    }
}

$svc = new JobService();
$mutex = new Mutex(new CacheEngineLockStore(new RedisCacheEngine()));

$result = MutexExecutor::callWithAttributes($svc, 'rebuildCache', mutex: $mutex);
```

Parameters: `name` (required — the lock name, shared by every caller of this method), `ttlSeconds`,
and `throwOnTimeout`.

The executor acquires before the call and releases in a `finally`, so the lock is freed even if the
method throws. With `throwOnTimeout: false`, a lock that never frees up makes the executor return
`null` **without running the method at all** — distinguish that from a genuine `null` result if the
method can return one.

## Overriding the timing

Both executors accept `waiterOverride`, which replaces the waiter the attribute would have built.
Its main use is tests: an attribute hard-codes real delays, and a suite should not sit through them.

```php
$fast = new ExponentialBackoffWaiter(
    initialDelayMicros: 1,
    maxTotalWaitMicros: 1_000,
    multiplier: 2.0
);

ExponentialBackoffExecutor::callWithAttributes($svc, 'fetch', waiterOverride: $fast);
```

Combined with the waiter's injectable clock, this lets a test cover a multi-second backoff policy
in virtual time — see [Testing without real delays](exponential-backoff.md#testing-without-real-delays).

## Any callable

Both executors have a `callFunctionWithAttributes()` variant that takes any callable and reads the
attribute from whatever it points to:

```php
#[ExponentialBackoff(maxTotalWaitMicros: 1_000_000)]
function fetchRemoteFlag(): ?bool { /* ... */ }

$flag = ExponentialBackoffExecutor::callFunctionWithAttributes('fetchRemoteFlag');
```

| Callable form | Example | Attribute read from |
|---|---|---|
| Function name | `'fetchRemoteFlag'` | the function |
| Static method string | `'ReportService::fetchStatic'` | the method |
| Array callable | `[ReportService::class, 'fetchStatic']`, `[$svc, 'fetch']` | the method |
| First-class callable | `$svc->fetch(...)` | the method |
| Closure | `#[ExponentialBackoff] fn() => $repo->get($id)` | the closure itself |
| Invokable object | `new FetchReport()` | its `__invoke()` method |

As with methods, a callable with no attribute is invoked directly — no retry, no locking.

## Use named arguments

The two executors do not take their arguments in the same order:

```php
ExponentialBackoffExecutor::callWithAttributes($obj, $method, array $args = [], ?callable $isSuccess = null, ?ExponentialBackoffWaiter $waiterOverride = null)
MutexExecutor::callWithAttributes($obj, $method, Mutex $mutex, array $args = [], ?ExponentialBackoffWaiter $waiterOverride = null)
```

`$args` is third in one and fourth in the other. Passing positionally will eventually bite; every
example above uses named arguments for that reason.
