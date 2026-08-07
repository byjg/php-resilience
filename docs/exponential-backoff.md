---
sidebar_position: 1
---

# Exponential Backoff

`ExponentialBackoffWaiter` retries an operation on a growing delay until it succeeds or a total
budget runs out. It replaces the hand-rolled polling loop that tends to appear whenever something
has to wait for a value that is not there yet:

```php
// The loop this exists to delete
do {
    $reward = $rewardRepo->get($id);
    if (is_null($reward)) {
        usleep($waitTime);
        $waitTime *= 2;
    }
} while (is_null($reward) && $waitTime < $maxTotalTime);
```

```php
use ByJG\Resilience\ExponentialBackoffWaiter;

$waiter = new ExponentialBackoffWaiter(
    initialDelayMicros: 200_000,   // 200ms
    maxTotalWaitMicros: 3_500_000, // 3.5s
    multiplier: 2.0
);

$reward = $waiter->wait(
    operation: fn() => $rewardRepo->get($id),
    isSuccess: fn($value) => $value !== null
);
```

## Constructor

| Parameter | Default | Meaning |
|---|---|---|
| `initialDelayMicros` | `200_000` | Delay before the second attempt, in microseconds. Must be > 0. |
| `maxTotalWaitMicros` | `3_500_000` | Total time budget for the whole call. Must be > 0. |
| `multiplier` | `2.0` | Growth factor applied to the delay after each failed attempt. Must be > 1.0. |
| `maxDelayMicros` | `null` | Ceiling on any single sleep. Null means uncapped. Must be > 0 when given. |
| `sleepFn` | `usleep(...)` | Injectable sleep, for tests. Receives microseconds. |
| `timeFn` | `microtime(true)` | Injectable clock, for tests. Returns float seconds. |

Out-of-range arguments throw `InvalidArgumentException` from the constructor rather than failing
later at call time.

## How the timing works

The first attempt happens **immediately** — there is no initial sleep. Delays only apply between
retries, and each one is trimmed so the total never exceeds `maxTotalWaitMicros`. With the defaults:

| Attempt | Sleep before it | Elapsed |
|---|---|---|
| 1 | — | 0 ms |
| 2 | 200 ms | 200 ms |
| 3 | 400 ms | 600 ms |
| 4 | 800 ms | 1400 ms |
| 5 | 1600 ms | 3000 ms |
| 6 | **500 ms** (trimmed from 3200) | 3500 ms |

That last row is the point: an unclamped sequence would sleep 3200 ms and return after 6.2 seconds,
nearly double the declared 3.5 second budget. `maxTotalWaitMicros` is a real ceiling on wall-clock
time, not just on the moment the loop decides to stop.

Use `maxDelayMicros` when you would rather retry often than let the interval grow without bound:

```php
$waiter = new ExponentialBackoffWaiter(
    initialDelayMicros: 400_000,
    maxTotalWaitMicros: 2_000_000,
    multiplier: 3.0,
    maxDelayMicros: 500_000   // sleeps: 400ms, 500, 500, 500, 100
);
```

## Deciding what counts as success

The default predicate is `$value !== null`, which suits repository lookups. Pass your own for
anything else:

```php
$response = $waiter->wait(
    operation: fn() => $http->get($url),
    isSuccess: fn($r) => $r->getStatusCode() < 500
);
```

The predicate receives whatever `operation` returned. `operation` may be called many times, so it
must be safe to repeat.

## When the budget runs out

By default a `ByJG\Resilience\Exceptions\TimeoutException` is thrown:

```php
use ByJG\Resilience\Exceptions\TimeoutException;

try {
    $value = $waiter->wait(fn() => $repo->get($id));
} catch (TimeoutException $e) {
    // never turned up in time
}
```

Pass `throwOnTimeout: false` to get the last value the operation produced instead — useful when
"not ready yet" is an ordinary outcome rather than an error:

```php
$value = $waiter->wait(fn() => $repo->get($id), null, false);   // null if it never arrived
```

## Testing without real delays

`sleepFn` and `timeFn` let a test drive virtual time, so a suite covering a 3.5 second budget still
runs instantly and deterministically:

```php
$now = 1000.0;
$sleeps = [];

$waiter = new ExponentialBackoffWaiter(
    initialDelayMicros: 200_000,
    maxTotalWaitMicros: 3_500_000,
    multiplier: 2.0,
    sleepFn: function (int $micros) use (&$sleeps, &$now): void {
        $sleeps[] = $micros;
        $now += (float)$micros / 1_000_000.0;   // advance the virtual clock
    },
    timeFn: fn(): float => $now
);

$waiter->wait(fn() => null, null, false);

// [200_000, 400_000, 800_000, 1_600_000, 500_000]
```

Both must be supplied together — the clamp is computed from `timeFn`, so a fake sleep with a real
clock would never advance and the wait would run for its full real duration.

## Also available

The same behaviour can be attached declaratively with `#[ExponentialBackoff]` — see
[Attributes](attributes.md).
