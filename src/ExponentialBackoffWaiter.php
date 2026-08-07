<?php

namespace ByJG\Resilience;

use ByJG\Resilience\Exceptions\TimeoutException;

/**
 * A generic exponential backoff waiter that repeatedly calls an operation until it succeeds
 * (default: returns non-null), sleeping between attempts with an exponentially increasing delay
 * up to a maximum total wait time.
 */
class ExponentialBackoffWaiter
{
    private int $initialDelayMicros;
    private int $maxTotalWaitMicros;
    private float $multiplier;
    private ?int $maxDelayMicros;
    private Jitter $jitter;

    /** @var callable(int $micros): void */
    private $sleepFn;

    /** @var callable(): float Returns current time in seconds with micro precision (like microtime(true)) */
    private $timeFn;

    /** @var callable(int $min, int $max): int */
    private $randomFn;

    /**
     * @param int $initialDelayMicros   Initial delay in microseconds (e.g., 200_000 for 200ms)
     * @param int $maxTotalWaitMicros   Maximum total wait time in microseconds (e.g., 3_500_000 for 3.5s)
     * @param float $multiplier         Backoff multiplier (e.g., 2.0 doubles each time)
     * @param int|null $maxDelayMicros  Optional cap for an individual sleep delay (microseconds)
     * @param callable|null $sleepFn    Injectable sleep function taking microseconds (defaults to usleep)
     * @param callable|null $timeFn     Injectable time provider returning float seconds (defaults to microtime(true))
     * @param Jitter $jitter            Randomness applied to each sleep, to stop callers retrying in lockstep.
     *                                  Defaults to Jitter::None so existing timing is unchanged unless asked for.
     * @param callable|null $randomFn   Injectable randomness taking (min, max) and returning an int in that
     *                                  inclusive range (defaults to random_int). Injecting it keeps jittered
     *                                  runs deterministic under test.
     */
    public function __construct(
        int $initialDelayMicros = 200_000,
        int $maxTotalWaitMicros = 3_500_000,
        float $multiplier = 2.0,
        ?int $maxDelayMicros = null,
        ?callable $sleepFn = null,
        ?callable $timeFn = null,
        Jitter $jitter = Jitter::None,
        ?callable $randomFn = null
    ) {
        if ($initialDelayMicros <= 0) {
            throw new \InvalidArgumentException('initialDelayMicros must be > 0');
        }
        if ($maxTotalWaitMicros <= 0) {
            throw new \InvalidArgumentException('maxTotalWaitMicros must be > 0');
        }
        if ($multiplier <= 1.0) {
            throw new \InvalidArgumentException('multiplier must be > 1.0');
        }
        if ($maxDelayMicros !== null && $maxDelayMicros <= 0) {
            throw new \InvalidArgumentException('maxDelayMicros must be > 0 when provided');
        }

        $this->initialDelayMicros = $initialDelayMicros;
        $this->maxTotalWaitMicros = $maxTotalWaitMicros;
        $this->multiplier = $multiplier;
        $this->maxDelayMicros = $maxDelayMicros;
        $this->sleepFn = $sleepFn ?? static function (int $micros): void {
            usleep($micros);
        };
        $this->timeFn = $timeFn ?? static function (): float {
            return microtime(true);
        };
        $this->jitter = $jitter;
        $this->randomFn = $randomFn ?? static function (int $min, int $max): int {
            return random_int($min, $max);
        };
    }

    /**
     * Waits until the operation is successful according to the predicate or until timeout.
     *
     * @template T
     * @param callable(): T $operation        Operation to try; it may be called multiple times.
     * @param callable(T): bool|null $isSuccess Predicate to determine success; defaults to non-null (value !== null).
     * @param bool $throwOnTimeout            Whether to throw TimeoutException on timeout (true) or return last value (false).
     * @return T|null                         Returns the successful value, or last value if timed out and $throwOnTimeout=false.
     * @throws TimeoutException               When timed out and $throwOnTimeout=true.
     */
    public function wait(callable $operation, ?callable $isSuccess = null, bool $throwOnTimeout = true)
    {
        $isSuccess = $isSuccess ?? static fn($v) => $v !== null;

        $start = ($this->timeFn)();
        $deadline = $start + ((float)$this->maxTotalWaitMicros / 1_000_000.0);

        $delay = $this->capDelay($this->initialDelayMicros);

        // First attempt immediately
        $value = $operation();
        if ($isSuccess($value)) {
            return $value;
        }

        while (true) {
            // Never sleep past the total wait deadline, otherwise the effective wait could be
            // several times larger than $maxTotalWaitMicros once the delay has grown.
            $remainingMicros = (int) round(($deadline - ($this->timeFn)()) * 1_000_000.0);
            if ($remainingMicros <= 0) {
                break;
            }

            ($this->sleepFn)(min($this->applyJitter($delay), $remainingMicros));

            $value = $operation();
            if ($isSuccess($value)) {
                return $value;
            }

            // increase delay for next round
            $delay = $this->capDelay((int) max(1, floor((float)$delay * $this->multiplier)));
        }

        if ($throwOnTimeout) {
            throw new TimeoutException('Operation did not succeed within the maximum total wait time');
        }

        return $value;
    }

    private function capDelay(int $micros): int
    {
        // The total budget is an implicit ceiling: a single sleep is clamped to whatever remains of
        // it anyway, so growing the delay past it buys nothing. Without this the series overflows -
        // jitter can make each sleep far shorter than the delay it was derived from, so the loop
        // iterates many more times than the series expects and $delay doubles past PHP_INT_MAX.
        $micros = min($micros, $this->maxTotalWaitMicros);

        return $this->maxDelayMicros !== null ? min($micros, $this->maxDelayMicros) : $micros;
    }

    /**
     * Randomise a single sleep without disturbing the growth series.
     *
     * $delay itself keeps multiplying deterministically; only the value handed to sleep is
     * randomised. That is what lets the backoff still escalate as configured while the callers
     * spread out across the interval instead of arriving together.
     *
     * Never returns 0: a zero-length sleep would retry the operation immediately, turning an
     * unlucky draw into a burst against the very dependency the backoff is protecting.
     */
    private function applyJitter(int $delay): int
    {
        $jittered = match ($this->jitter) {
            Jitter::None => $delay,
            Jitter::Full => ($this->randomFn)(0, $delay),
            Jitter::Equal => intdiv($delay, 2) + ($this->randomFn)(0, intdiv($delay, 2)),
        };

        return max(1, $jittered);
    }
}
