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

    /** @var callable(int $micros): void */
    private $sleepFn;

    /** @var callable(): float Returns current time in seconds with micro precision (like microtime(true)) */
    private $timeFn;

    /**
     * @param int $initialDelayMicros   Initial delay in microseconds (e.g., 200_000 for 200ms)
     * @param int $maxTotalWaitMicros   Maximum total wait time in microseconds (e.g., 3_500_000 for 3.5s)
     * @param float $multiplier         Backoff multiplier (e.g., 2.0 doubles each time)
     * @param int|null $maxDelayMicros  Optional cap for an individual sleep delay (microseconds)
     * @param callable|null $sleepFn    Injectable sleep function taking microseconds (defaults to usleep)
     * @param callable|null $timeFn     Injectable time provider returning float seconds (defaults to microtime(true))
     */
    public function __construct(
        int $initialDelayMicros = 200_000,
        int $maxTotalWaitMicros = 3_500_000,
        float $multiplier = 2.0,
        ?int $maxDelayMicros = null,
        ?callable $sleepFn = null,
        ?callable $timeFn = null
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

            ($this->sleepFn)(min($delay, $remainingMicros));

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
        return $this->maxDelayMicros !== null ? min($micros, $this->maxDelayMicros) : $micros;
    }
}
