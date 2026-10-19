# Roadmap: Additional Resilience Patterns

The library currently ships **Mutex** and **ExponentialBackoff**. This document surveys the
remaining resilience patterns, what each one solves, how it maps onto the primitives already
present in this codebase, and the traps in each.

## Summary

| # | Tool | Primitive needed | Effort | Value |
|---|---|---|---|---|
| 0 | Pipeline | — | M | **Precondition for everything below** |
| 1 | Retry + jitter | — | **S** | **Highest** — fills a real gap |
| 2 | Circuit breaker | counter (+ mutex for half-open) | M | **Highest** — the missing pattern |
| 3 | Single-flight | already have both | S | High — most common mutex use |
| 4 | Rate limiter | counter | M | High |
| 5 | Bulkhead | `LockStoreInterface` as-is | M | Medium-high |
| 6 | Fallback | — | XS | Medium |
| 7 | Idempotency | mutex + result store | M | Medium |
| 8 | Lease | `refreshIfOwner()` as-is | S | Medium |
| 9 | Deadline | — | M | Medium ⚠️ caveats |

**Suggested sequence:** pipeline → retry+jitter → circuit breaker (+ counter store). That yields
retry, breaker, mutex and backoff — the four patterns covering most real failure modes — on a base
that can absorb rate limiting and bulkhead later without another executor class.

Two items are unusually cheap given what is already built: **jitter on the existing waiter** (one
optional constructor parameter, fixes a real thundering-herd flaw today), and the **mutex-guarded
half-open probe**, which solves the hard part of distributed circuit breaking with a primitive that
is already tested.

## Available primitives

Two of the three atomic primitives are already in use:

| Primitive | Source | Powers |
|---|---|---|
| compare-and-swap | `LockStoreInterface` / `ByJG\Cache\CompareAndSwapInterface` | Mutex ✅ |
| wall-clock timing | `ExponentialBackoffWaiter` | Backoff ✅ |
| **atomic counter** | `ByJG\Cache\AtomicOperationInterface` — `increment()`/`decrement()` **with TTL**, implemented by Redis, Memcached, FileSystem | *unused* |

The counter primitive is the basis for circuit breakers, rate limiters and semaphores. Mirroring
`LockStoreInterface` with a `CounterStoreInterface` + `CacheEngineCounterStore` opens all three at
once. `docker-compose.yml` already runs both engines that support it.

⚠️ Two caveats on that row, both easy to get wrong:

- **cache-engine is not a required dependency.** It is `require-dev` + `suggest`, so the library
  stays usable with a hand-written store over a raw client. A `CounterStoreInterface` must follow
  the same optional-adapter shape as `CacheEngineLockStore` rather than assuming the package is
  present.
- **`increment()` with a TTL only became trustworthy in cache-engine 7.0.** In 6.x the TTL was
  written to FileSystem as an absolute timestamp of `60`, i.e. 1970, so the counter was expired the
  moment it was written; and Memcached seeded a missing key with a non-atomic `get()`-then-`set()`,
  losing 12 of 20 concurrent increments in a forked test. Everything in Tier 2 therefore depends on
  byjg/php-cache-engine#24 landing and 7.0.0 being tagged.

---

## 0. The pipeline (precondition)

**What it is.** Every resilience policy has the same shape: it takes a `callable` and returns a
`callable` that does something extra around it. They compose by nesting, like middleware.

```php
$pipeline = new ResiliencePipeline([
    new FallbackPolicy(fn() => $cached),
    new RetryPolicy(maxAttempts: 3),
    new CircuitBreakerPolicy($breaker),
    new BulkheadPolicy($semaphore),
]);

$result = $pipeline->execute(fn() => $api->fetch($id));
```

**Why order matters — the part people get wrong.** Conventional ordering, outermost to innermost:

```
Fallback  →  Retry  →  CircuitBreaker  →  Bulkhead  →  Timeout  →  operation
```

- **Retry outside the breaker**, so each individual attempt is checked against the breaker and each
  failed attempt is counted. Inverted, the breaker sees one "call" that internally burned three
  attempts — it counts wrong, and retries keep hammering a dependency the breaker already gave up
  on.
- **Timeout innermost**, so it is *per attempt*, not for the whole retry sequence. Usually you want
  both: a per-attempt timeout here and an overall deadline at the top.
- **Fallback outermost**, because it is the last resort and must catch whatever escapes.

**Why first.** `ExponentialBackoffExecutor` and `MutexExecutor` currently duplicate the same
reflection-and-dispatch logic. At two policies that is tolerable; at five it is duplicated code with
no way to stack `#[Retry]` + `#[CircuitBreaker]` on one method. One `ResilienceExecutor` would read
*all* resilience attributes on a method, sort them into pipeline order and build the chain — so
adding a sixth policy means writing one class, not one class plus one executor.

---

## Tier 1 — real gaps, buildable with existing primitives

### 1. Retry with jitter

**The gap.** `ExponentialBackoffWaiter::wait()` calls `$operation()`, then tests
`$isSuccess($value)`. If `$operation()` **throws**, the exception propagates straight out — no
retry, no backoff. The class is a *poller*: "keep asking until the answer isn't null." That is the
right tool for "the reward record hasn't been written yet."

It is not the tool for "the HTTP call threw a 503." Different policies, different configuration:

| | current waiter | a retry policy |
|---|---|---|
| triggers on | falsy return value | thrown exception |
| needs to know | success predicate | *which* exceptions are retryable |
| a 400 Bad Request | n/a | must **not** retry — it will fail identically forever |
| a 503 / timeout | n/a | must retry |

Retrying a non-retryable error is worse than not retrying: one fast failure becomes three slow ones.
So a retry policy needs `retryOn` / `abortOn` exception lists, and ideally a
`shouldRetry(Throwable): bool` predicate for "retry HTTP 429 and 5xx, abort on 4xx."

**Jitter — the reason this matters.** The current backoff is deterministic: 200ms, 400ms, 800ms,
1600ms. With 50 workers whose calls all fail at the same moment (the database went down), all 50
sleep exactly 200ms, all 50 retry at the same instant, all 50 fail, all 50 sleep exactly 400ms. That
is a synchronized herd hitting the recovering database in tight coordinated waves — the worst
possible pattern, and the reason it never gets a quiet moment to recover.

Jitter randomizes the sleep to spread the herd. Standard variants (AWS, *Exponential Backoff and
Jitter*):

```
full jitter:         sleep = rand(0, min(cap, base * 2^n))
equal jitter:        temp  = min(cap, base * 2^n)
                     sleep = temp/2 + rand(0, temp/2)
decorrelated jitter: sleep = min(cap, rand(base, prev * 3))
```

Full jitter gives the best spread; equal jitter guarantees a minimum wait. Either is a handful of
lines.

> **The existing waiter needs jitter too**, for the same reason — 50 workers polling for the same
> not-yet-written record is the identical herd. One optional `$jitter` constructor parameter on a
> class that already exists; highest value-to-effort item on this list.

### 2. Circuit breaker

**The problem.** Retry helps with *transient* faults — a blip, a dropped packet. It actively hurts
with *sustained* faults. If a dependency has been down for ten minutes, every request still pays the
full timeout (say 30s) before failing, three times over. The worker pool fills with threads blocked
on a service that is not going to answer, and the outage spreads to callers that had nothing to do
with it. That is a cascading failure.

A circuit breaker fails *fast* instead. After enough failures it stops calling the dependency and
throws immediately — protecting local resources, and giving the struggling dependency room to
recover instead of a constant retry barrage.

**Three states:**

- **Closed** — normal. Calls pass through, failures counted.
- **Open** — tripped. Calls rejected instantly with `CircuitOpenException`; the dependency is never
  touched. Stays open for `breakDuration`.
- **Half-open** — probation. After `breakDuration`, allow a *small* number of trial calls. Succeed →
  Closed. Fail → straight back to Open, timer resets.

**Counting strategy:**

- *Consecutive failures* — trip after N in a row. Dead simple, one `increment()`, reset to zero on
  success. Weakness: a service failing 50% of the time may never hit N consecutively while being
  thoroughly broken.
- *Failure rate in a rolling window* — trip when >X% of the last N calls failed, with a
  minimum-throughput floor so 1 failure out of 2 calls does not trip it. More correct, needs
  bucketed counters.

Start with consecutive; leave interface room for the second.

**Where this library has an unusual advantage.** In a distributed breaker the half-open state is the
hard part. When the break duration elapses across a fleet of 50 processes, *all 50* decide it is
probe time and send a trial call simultaneously — re-hammering the recovering service with exactly
the burst the breaker existed to prevent.

The fix is to let only one process probe. That is a distributed mutex with a short TTL — already
shipped:

```php
// only the winner probes; everyone else keeps fast-failing
$token = $this->mutex->tryAcquire("breaker:$name:probe", ttlSeconds: 5);
if ($token === null) {
    throw new CircuitOpenException();
}
```

Most PHP breaker libraries either skip distributed state or get this wrong. Here it falls out of a
primitive that is already tested.

**Ship both** a local in-process breaker (no store, fine for a long-running worker) and a
distributed one over the counter store.

### 3. Single-flight / stampede protection

**The problem.** A hot cache key expires. In the microsecond before anything repopulates it, 200
concurrent requests all miss, and all 200 run the expensive query. The database takes the full load
it was cached to avoid — often at peak traffic, because that is when the key is hottest. This is
*cache stampede*, or the *dogpile effect*.

**The fix** is the two existing classes composed:

1. Cache miss → try to acquire a mutex on that key.
2. **Winner**: runs the expensive operation, writes the cache, releases.
3. **Losers**: run nothing. They wait on `ExponentialBackoffWaiter` polling the cache and pick up
   the value the winner wrote.

One database query instead of 200, and the 199 losers still get their answer.

This is already writable by hand with `Mutex` + `ExponentialBackoffWaiter` — which is precisely the
argument for shipping it as a named class. It is the most common real-world use of a distributed
mutex, and hand-rolling it invites subtle bugs (what if the winner crashes? what if it throws? what
TTL relative to the backoff budget?). Getting those right once, in tested code, is the value.

⚠️ **TTL coupling:** the losers' `maxTotalWaitMicros` must exceed the winner's worst-case runtime,
or they time out while the winner is still legitimately working.

---

## Tier 2 — needs a counter store

Both want `ByJG\Cache\AtomicOperationInterface` (`increment`/`decrement`, **with TTL applied
atomically**). The pattern mirrors the existing lock store:

```php
interface CounterStoreInterface {
    public function incrementAndGet(string $key, int $by, int $ttlSeconds): int;
    // ...
}
final class CacheEngineCounterStore implements CounterStoreInterface { /* wraps AtomicOperationInterface */ }
```

Same reasoning as `LockStoreInterface`: take the narrow atomic interface in the constructor so an
engine that cannot make the guarantee cannot be passed in at all.

### 4. Rate limiter

**Two distinct jobs**, worth separating in the API:

- **Admission control** — reject over-limit calls immediately (`RateLimitExceededException`). For
  protecting *yourself*.
- **Pacing** — wait for a slot instead of rejecting. For respecting *someone else's* published
  limit, e.g. an API allowing 100 req/min. Reuses the waiter.

**Algorithms, ordered by what `increment()` can actually express:**

- **Fixed window.** Key is `limit:$resource:$windowNumber`; one `increment()` with TTL = window
  length; over limit → reject. One round trip, trivially correct, self-expiring. **Flaw:** boundary
  bursts — with a limit of 100/min, 100 calls at 11:59:59 and 100 more at 12:00:01 both pass, 200
  calls in two seconds. Fine for rough protection, genuinely ~15 lines.
- **Sliding window counter.** Keep current and previous window counters, weight the previous one by
  how far into the current window you are. Smooths the boundary for two reads plus one increment.
  Best accuracy-per-complexity; the sensible default.
- **Token bucket.** Nicest semantics — steady refill rate plus burst allowance. Needs an atomic
  read-modify-write over a *pair* (`tokens`, `lastRefillTime`), which plain `increment()` cannot
  express; requires a CAS retry loop or a Redis Lua script. Doable, noticeably more work.
- **Sliding window log.** Exact, stores every timestamp, memory grows with traffic. Not worth it.

### 5. Bulkhead / distributed semaphore

**The name** comes from ship hulls: watertight compartments, so one breach floods one compartment
instead of sinking the vessel. Same idea for a worker pool — cap how many workers can be inside one
dependency at a time, so a slow dependency cannot consume every worker and take the application down
with it. A breaker handles *failing* dependencies; a bulkhead handles *slow* ones, which are often
more dangerous because nothing errors, things just pile up.

**A mutex is a semaphore of 1.** The generalization is N.

**Two implementations, and the choice matters:**

- *Counter-based*: `increment()`, if the result exceeds N then `decrement()` and reject. Simple —
  but a holder that **crashes** never decrements. The counter drifts up permanently and the bulkhead
  eventually blocks everything, forever, until someone clears the key by hand. A real operational
  trap.
- *Slot-based*: N mutex keys, `sem:$name:0` … `sem:$name:N-1`. Try each until one succeeds. A
  crashed holder's slot **frees itself when the TTL expires** — crash recovery for free, from
  machinery already built and tested.

Slot-based is clearly right here: it reuses `LockStoreInterface` unchanged, needs no new store, and
inherits the token/TTL/ownership model `Mutex` already gets correct. Cost is up to N round trips to
find a free slot; start the scan at a random offset so callers do not all stampede slot 0.

---

## Tier 3 — cheap, mostly composition

### 6. Fallback

Return a default value, a stale cached copy, or a degraded response instead of propagating the
failure. Nearly trivial in isolation — its value is being the terminal stage of a pipeline, and
having a consistent place to decide *which* exceptions get a fallback versus propagating. Also the
natural home for "serve stale cache on failure", which is what most graceful degradation actually
looks like.

### 7. Idempotency key

**The problem.** A payment webhook is delivered twice — the sender retried, or our own retry policy
fired after a response was lost in transit. The card gets charged twice.

**The mechanism.** Key the operation by a caller-supplied idempotency key and store a small state
machine: `in-progress` → `completed(result)`. A second call with the same key either waits
(in-progress) or returns the stored result (completed) without re-executing.

This is `Mutex` plus a memoized result. Design questions to settle first: how long to retain results
(a business decision — how late can a duplicate arrive?), how to serialize them, and what happens
when the first attempt *failed* — cached failure, or fresh attempt?

⚠️ Honest limitation: this makes execution idempotent *from our side*. It cannot help if the side
effect lives in a third-party system that is not itself idempotent.

### 8. Lease / leader election

Pick one process out of a fleet to run a scheduled job, consume a queue, or hold a role. It is a
mutex with a TTL the holder keeps renewing — and `refreshIfOwner()` already exists, so most of the
way there. Missing: the loop (a long-running worker calling `refresh()` on a heartbeat, since PHP
has no background threads) and a clean "I lost the lease" signal so it can stop working immediately.

⚠️ **Document in `docs/lock-stores.md` under "what this does not guarantee".** A lease can expire
*while the holder still believes it holds it* — a long GC pause, a stopped VM, a network partition.
The holder wakes up, thinks it is still leader, and writes; meanwhile someone else legitimately took
the lease. This is Kleppmann's critique of Redlock and applies to any TTL lock, this one included.

The mitigation is a **fencing token**: a monotonically increasing number issued with each lease,
passed to the protected resource, which rejects any write carrying a token lower than the highest it
has seen. That requires cooperation from the resource, so the library cannot provide it alone — but
it *can* issue the token, and the docs can explain what it is for.

---

## Tier 4 — recommend skipping

### 9. Timeout ⚠️

Looks easiest, is not. **PHP cannot preempt synchronous code.** There is no way to write a general
`withTimeout(5, $anyCallable)` that reliably interrupts arbitrary work:

- `set_time_limit()` applies to the whole script, not a section, and on many platforms does not
  count time spent inside system calls — exactly where a hung network call is sitting.
- `pcntl_alarm()` + `pcntl_async_signals(true)` works, but only on CLI with ext-pcntl, and plenty of
  extensions swallow the `EINTR` and simply resume their blocking read.
- Fibers are *cooperative* — they yield only where the code chose to. A blocking `curl_exec` never
  yields.

The version that works is a **`Deadline` value object**: computed once at the top of the request,
passed down, translated into each client's own native timeout — `CURLOPT_TIMEOUT_MS`,
`PDO::ATTR_TIMEOUT`, stream context timeouts, Redis read timeout. Plus one genuinely useful thing
the library *can* enforce alone: **check the deadline between retry attempts**, so a 3-attempt retry
with a 30s timeout each cannot quietly consume 90 seconds against a 10-second budget. That part is
real, portable and worth having.

Do not name it `Timeout` and let people assume it wraps anything. Name it `Deadline`, and state the
limitation prominently.

### 10. Hedged requests

Fire a second attempt if the first has not answered by the p95 latency mark, take whichever returns
first. Excellent for tail-latency reduction — and it needs genuine concurrency, which synchronous
PHP does not have. Skip.

### 11. Outbox / dead-letter queue

Both are real resilience patterns, but they are messaging-infrastructure concerns with a completely
different dependency surface (a database with transactions, a broker). Different library.

---

## TASKS

Working list. The survey above is the reasoning; this section is the agreed order and the acceptance
criteria.

| #  | Task                              | Needs                        | Status  |
|----|-----------------------------------|------------------------------|---------|
| 1  | Jitter                            | —                            | ✅ done |
| 2  | Retry on exception                | `BackoffSchedule` extraction | next    |
| 3  | Pipeline / `ResilienceExecutor`   | —                            |         |
| 4  | Fallback                          | —                            |         |
| 5  | Single-flight                     | mutex + waiter, as-is        |         |
| 6  | Bulkhead (slot-based)             | `LockStoreInterface`, as-is  |         |
| 7  | `CounterStoreInterface` + adapter | ⚠️ cache-engine 7.0.0 tagged |         |
| 8  | Circuit breaker                   | counter + mutex              |         |
| 9  | Rate limiter                      | counter                      |         |
| 10 | Lease + fencing tokens            | `refreshIfOwner()`, as-is    |         |
| 11 | Idempotency key                   | mutex + result store         |         |

Two ordering principles, both deviating from the *Suggested sequence* at the top of this document.

**The pipeline is not first.** With two policies you would design a composition model against one
real case and four imagined ones. The duplication it removes is roughly 40 lines across the two
executors — real, but not yet expensive. Building retry first means the pipeline gets designed
against two genuinely different shapes (one wraps-and-retries, one acquires-and-releases), which is
a far better test of the abstraction than speculation.

**Unblocked work first.** Everything from task #7 onwards waits on cache-engine 7.0.0 being tagged,
whereas single-flight and bulkhead need no new primitives at all — they compose classes that already
exist and are already tested. So those come before the counter store rather than after it, and the
external dependency gates as little as possible.

Three surveyed items are deliberately absent; see [Not planned](#not-planned).

### TASK #1 — Jitter on `ExponentialBackoffWaiter` ✅ done

The only item on this list that fixes something already broken rather than adding capability.
`wait()` is deterministic today: `$delay = capDelay(max(1, floor($delay * $this->multiplier)))`,
no randomness anywhere. Fifty workers failing at the same instant sleep 200 ms, retry together, fail
together, sleep 400 ms, retry together.

It fixes **both** shipped features at once, which the survey above undersells: `Mutex::acquire()`
polls through the same waiter, so every contender for a busy lock currently retries in lockstep too.

Scope:

- `Jitter` enum: `None` (default), `Full` (`rand(0, delay)`), `Equal` (`delay/2 + rand(0, delay/2)`).
- Jitter transforms the **sleep**, never the growth series. `$delay` keeps doubling deterministically
  so the backoff still escalates; only the actual sleep is randomised. This is the AWS formulation.
- Injectable `randomFn`, mirroring the existing `sleepFn` / `timeFn`. Non-negotiable: two shipped
  tests assert exact sleep sequences to prove the deadline clamp, and they must keep asserting exact
  values rather than being weakened to ranges.
- Default `None`. Randomising existing timing by default is a surprise for anyone already relying
  on it.

Not in scope: **decorrelated jitter** (`min(cap, rand(base, prev * 3))`). It replaces the
multiplier-driven growth model rather than transforming its output, so it is a different class of
change, not a third enum case.

Open decision: should `Mutex::acquire()`'s default waiter use `Jitter::Full`? Lock contention is
precisely the herd jitter exists for, but it changes `Mutex` behaviour, so it is called out rather
than slipped in.

### TASK #2 — Retry on exception

The gap a library called "resilience" is expected to cover. `ExponentialBackoffWaiter` is a
*poller*: it reacts to a returned value failing a predicate, and a thrown exception propagates
immediately with no retry. Retrying a *non-retryable* error is worse than not retrying — one fast
failure becomes three slow ones — so this cannot be bolted onto the waiter by catching `Throwable`.

#### Precondition: extract `BackoffSchedule`

Reusing the waiter "for the delay schedule" is the right instinct but is not currently possible.
`ExponentialBackoffWaiter`'s entire public surface is `__construct()` and `wait()`; `capDelay()` and
`applyJitter()` are private, and `wait()` *is* the loop — it insists on invoking the operation and
judging the result with a predicate. There is no "how long should I wait next" call to delegate to.
Driving exception-retry through `wait()` anyway means boxing the return value to distinguish a
legitimate `null` from a failure, swallowing the exception inside the closure where the
retryable/abort decision has to happen, and surfacing the final failure as `TimeoutException`
instead of the real error. That is a workaround, not reuse.

The second, harder half: **`wait()` terminates on `maxTotalWaitMicros`, not on an attempt count.**
There is no `maxAttempts` concept anywhere in it. Retry needs one. Asking a single object to be
driven by both termination models at once is where this design breaks down.

So extract the delay series — the growth multiplication, `capDelay()`, `applyJitter()`, and the
remaining-budget clamp — into a `BackoffSchedule` that yields successive sleeps. `wait()` consumes
it and keeps its deadline model unchanged; `Retry` consumes it and applies its own attempt cap.
Roughly 25 lines moving out of `ExponentialBackoffWaiter` with no behaviour change, landed as a
standalone refactor with the existing tests green *before* any retry code is written.

Two reasons this is worth doing properly rather than reimplementing the series inside `Retry`: the
comments at `capDelay()` and `applyJitter()` exist because both were subtle to get right, and
writing them twice invites the two copies to drift; and TASK #3 would otherwise have to untangle the
same duplication later.

#### Shape

```
BackoffSchedule            // delay series only — no operation, no predicate, no loop
  initialDelayMicros, multiplier, maxDelayMicros, maxTotalWaitMicros, jitter, randomFn
  → yields successive sleep durations, clamped to the remaining budget

Retry                      // ByJG\Resilience\Retry
  maxAttempts: int = 3                     // total tries, including the first
  retryOn: array<class-string> = [Exception::class]
  abortOn: array<class-string> = []        // checked first; deny beats allow
  shouldRetry: ?callable(Throwable): bool  // checked last, full control
  onRetry: ?callable(Throwable, int $attempt, int $delayMicros): void
  schedule: BackoffSchedule

  execute(callable $operation): mixed
```

`Retry` holds no per-call state. An earlier draft put `lastException` on the policy object; that
gives a configured-once, reused-everywhere object mutable per-call state, so a shared instance hands
back stale diagnostics from an unrelated call — and it duplicates what the thrown exception already
carries.

Naming follows the shipped convention (`Mutex` / `Attributes\Mutex`), so it is `Retry`,
`Attributes\Retry` and `RetryExecutor` — not `RetryPolicy` / `RetryAttribute`.

#### Decisions

**Default `retryOn` is `Exception`, not `Throwable`.** Retrying a `TypeError` or `ParseError` is
never correct — those are bugs, not transient faults, and retrying one is exactly the "one fast
failure becomes three slow ones" case this task exists to avoid.

**`abortOn` beats `retryOn`.** Deny-over-allow is the least surprising precedence when a type
matches both.

**Its own executor, not folded into `ExponentialBackoffExecutor`.** The duplication is ~30-40 lines
today. Keeping them separate gives TASK #3 two genuinely different shapes to compose against — one
wraps-and-retries, one acquires-and-releases — which is the whole reason the pipeline comes second.

**`shouldRetry` cannot be expressed as an attribute argument.** Attribute arguments must be constant
expressions and there is no way to write a callable as one — the same limit already documented on
`Attributes\ExponentialBackoff` for `randomFn`. `retryOn` / `abortOn` are class-string lists and are
fine. A caller needing `shouldRetry` passes a configured `Retry` through the executor's override,
worded the same way as the existing case.

#### Open decision: exhaustion

Rethrow the last exception, or wrap it in a `RetryExhaustedException` carrying every attempt?

The tradeoff is narrower than it first looks. Wrapping does *not* lose access to the original —
`getPrevious()` returns it. What it costs is that `catch (ConnectionException $e)` at the call site
stops working and every caller has to know to unwrap.

Recommended: **rethrow, plus an `onRetry` callback.** The attempt history is wanted for logging, and
a callback delivers it per-attempt as it happens rather than post-mortem, so rethrowing does not
actually lose it. The two are not exclusive — if the wrapper is wanted as well, `onRetry` for
observability plus `RetryExhaustedException` behind an opt-in flag gets both without forcing
unwrapping on callers who do not want it.

If the wrapper is adopted, it is `RetryExhaustedException extends RuntimeException` with
`attempts(): int` and `allExceptions(): array<Throwable>`. Collect the exceptions eagerly or not at
all — they are already in memory by the time it is thrown, so there is nothing to defer.

#### Acceptance criteria

1. `BackoffSchedule` extracted first, as its own commit, with the existing `ExponentialBackoffWaiter`
   tests unchanged and green — including the two that assert exact sleep sequences.
2. `Retry::execute(fn() => throw new MyException())` with `maxAttempts: 3` invokes the operation
   three times, sleeps on the backoff series between them, then fails per the exhaustion decision.
3. `retryOn: [ConnectionException::class]` — any other exception propagates immediately, uncaught,
   with no sleep and no further invocation.
4. `abortOn: [InvalidArgumentException::class]` — never retried, even when it also matches `retryOn`.
5. `shouldRetry: fn($e) => $e instanceof HttpException && $e->code >= 500` overrides the class lists.
6. A `TypeError` is not retried under the default configuration.
7. `#[Retry(maxAttempts: 3, retryOn: [ConnectionException::class])]` on a method works through
   `RetryExecutor`.
8. Jitter reaches the retry path: a `Retry` configured with `Jitter::Full` and an injected `randomFn`
   produces the expected jittered sleep sequence.
9. All tests use the injected clock, sleep and randomness — no real delays, fully deterministic.

#### Not in scope

- **Pipeline / `ResilienceExecutor`** — TASK #3. This task should inform the pipeline design, not
  the other way round.
- **HTTP-specific predicates.** `shouldRetry` covers it without baking HTTP semantics into the core.
- **Fallback and circuit-breaker composition** — TASK #4 and TASK #8; ordering is the pipeline's job.
- **Changing `Mutex::acquire()`'s default jitter.** Still open from TASK #1, but it is a behaviour
  change to a shipped feature and should be decided on its own rather than riding along with a new
  one.

### TASK #3 — The pipeline / `ResilienceExecutor`

Now, not first. With retry built there are two policies of genuinely different shape — one
wraps-and-retries, one acquires-and-releases — which is a real test of the abstraction rather than a
guess at four imagined ones.

Scope:

- `ResiliencePipeline` composing policies as nested callables, outermost first.
- One `ResilienceExecutor` replacing `ExponentialBackoffExecutor` and `MutexExecutor`: read *all*
  resilience attributes on a method, sort them into pipeline order, build the chain. Adding a policy
  then means one class, not one class plus one executor.
- Fix the argument-order inconsistency while collapsing them (`$args` is third in one executor and
  fourth in the other), currently papered over by documenting named arguments.

The ordering rule is the part to get right, and it is not arbitrary:

```
Fallback  →  Retry  →  CircuitBreaker  →  Bulkhead  →  Deadline  →  operation
```

Retry must sit **outside** the breaker so each attempt is checked and counted individually.
Inverted, the breaker sees one call that internally burned three attempts, counts wrong, and retries
keep hammering a dependency it already gave up on.

### TASK #4 — Fallback

XS, and worth doing straight after the pipeline because it is the terminal stage — it exercises
composition end to end with almost no new code. Returns a default, a stale cached copy, or a
degraded response instead of propagating.

The value is not the try/catch; it is having one consistent place to decide *which* exceptions get a
fallback and which propagate, and being the natural home for "serve stale cache on failure", which
is what most graceful degradation actually is.

### TASK #5 — Single-flight / stampede protection

No new primitives: `Mutex` + `ExponentialBackoffWaiter`, composed. Winner runs the expensive
operation and writes the cache; losers poll and pick up what the winner wrote. One query instead of
200, and the 199 losers still get an answer.

That it is already hand-writable is the argument *for* shipping it — it is the most common real use
of a distributed mutex, and hand-rolling invites exactly the bugs worth solving once in tested code:
what if the winner throws, what if it crashes, what TTL relative to the losers' wait budget.

⚠️ The losers' `maxTotalWaitMicros` must exceed the winner's worst-case runtime, or they time out
while the winner is still legitimately working. That coupling is the thing to document loudly.

### TASK #6 — Bulkhead / distributed semaphore

Caps how many callers can be inside one dependency at a time, so a *slow* dependency cannot consume
every worker. A breaker handles failing dependencies; a bulkhead handles slow ones, which are often
more dangerous because nothing errors — things just pile up.

**Slot-based, not counter-based.** N mutex keys, `sem:$name:0` … `sem:$name:N-1`, try each until one
succeeds. A crashed holder's slot frees itself when the TTL expires. The counter-based alternative
(`increment`, reject and `decrement` if over N) has a holder that crashes never decrementing: the
counter drifts up permanently until someone clears the key by hand.

So this needs no new store at all — it reuses `LockStoreInterface` unchanged and inherits the
token/TTL/ownership model `Mutex` already gets right. Start the slot scan at a random offset so
callers do not all stampede slot 0.

### TASK #7 — `CounterStoreInterface` + `CacheEngineCounterStore`

⚠️ **Blocked on byjg/php-cache-engine#24 merging and 7.0.0 being tagged.** The only external
dependency in this list. See the caveats under *Available primitives* — `increment()` with a TTL was
broken on FileSystem and racy on Memcached before 7.0.

Mirrors the lock store exactly: a narrow interface taking the atomic primitive in the constructor,
so an engine that cannot make the guarantee cannot be passed in. Optional adapter shape, since
cache-engine is `require-dev` + `suggest`, not required.

Unblocks tasks #8 and #9 together.

### TASK #8 — Circuit breaker

The missing pattern. Retry helps with transient faults and actively hurts with sustained ones: if a
dependency has been down ten minutes, every request still pays the full timeout, three times over,
until the worker pool fills with threads blocked on a service that will not answer.

Scope:

- Three states — closed, open, half-open — with `CircuitOpenException` on rejection.
- Consecutive-failure counting first; leave interface room for a rolling failure rate, which is more
  correct but needs bucketed counters. A service failing 50% of the time may never hit N
  consecutive failures while being thoroughly broken.
- Ship a local in-process breaker as well as a distributed one; the local one needs no store and is
  fine for a long-running worker.

**Where this library has an edge.** In a distributed breaker the half-open state is the hard part:
when the break duration elapses across 50 processes, all 50 decide it is probe time and send a trial
call simultaneously — re-hammering the recovering service with exactly the burst the breaker
existed to prevent. Letting only one process probe is a short-TTL distributed mutex, which is
already shipped and tested. Most PHP breaker libraries skip distributed state or get this wrong.

### TASK #9 — Rate limiter

Two distinct jobs, worth separating in the API: **admission control** (reject over-limit calls
immediately, protecting yourself) and **pacing** (wait for a slot, respecting someone else's
published limit — reuses the waiter, and now its jitter).

Default to the **sliding window counter**: current and previous window counters, previous weighted
by how far into the current window you are. Best accuracy per unit of complexity, two reads plus one
increment.

Fixed window is ~15 lines but has boundary bursts — 100 calls at 11:59:59 and 100 more at 12:00:01
both pass a 100/min limit. Token bucket has the nicest semantics but needs an atomic
read-modify-write over a *pair* (`tokens`, `lastRefillTime`), which `increment()` cannot express;
it needs a CAS retry loop or a Lua script. Sliding window log is exact and grows with traffic — skip.

### TASK #10 — Lease / leader election, and fencing tokens

Mostly already built: a lease is a mutex whose holder keeps renewing the TTL, and `refreshIfOwner()`
exists. Missing are the heartbeat loop and a clean "I lost the lease" signal so a worker stops
immediately rather than continuing to act as leader.

The genuinely new part is documentation, and it is nearly free because
`docs/lock-stores.md#what-this-does-not-guarantee` already exists and already covers TTL lapse — it
just does not mention **fencing tokens**. A lease can expire while the holder still believes it
holds it (a long GC pause, a stopped VM, a partition); the holder wakes and writes while someone
else legitimately holds the lease. This is Kleppmann's critique of Redlock and applies to any TTL
lock, including this one.

The mitigation is a monotonically increasing token issued with each lease and passed to the
protected resource, which rejects writes carrying a token lower than the highest it has seen. That
needs cooperation from the resource, so the library cannot provide it alone — but it *can* issue the
token, and the docs can explain what it is for.

### TASK #11 — Idempotency key

`Mutex` plus a memoized result: key the operation by a caller-supplied idempotency key and store
`in-progress` → `completed(result)`. A duplicate either waits or returns the stored result without
re-executing.

Last because the open questions are business decisions, not technical ones: how long results are
retained (how late can a duplicate arrive?), how they are serialized, and what happens when the
first attempt *failed* — cached failure or fresh attempt.

⚠️ This makes execution idempotent *from our side only*. It cannot help when the side effect lives
in a third-party system that is not itself idempotent.

---

## Not planned

Stated explicitly rather than silently dropped.

- **Timeout.** PHP cannot preempt synchronous code — `set_time_limit()` is script-wide and often
  does not count time inside system calls, `pcntl_alarm()` is CLI-only and plenty of extensions
  swallow `EINTR` and resume their blocking read, and fibers are cooperative so a blocking
  `curl_exec` never yields. The workable version is a `Deadline` value object translated into each
  client's own native timeout (`CURLOPT_TIMEOUT_MS`, `PDO::ATTR_TIMEOUT`, stream context, Redis read
  timeout). The one part this library *can* enforce alone — checking the deadline **between** retry
  attempts, so three attempts at 30s each cannot quietly consume 90 seconds against a 10-second
  budget — folds into TASK #2 instead. Do not name it `Timeout` and let people assume it wraps
  anything.
- **Hedged requests.** Needs genuine concurrency, which synchronous PHP does not have.
- **Outbox / dead-letter queue.** Real patterns, but messaging-infrastructure concerns with a
  completely different dependency surface — a database with transactions, a broker. Different
  library.
