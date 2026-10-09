<?php

namespace ByJG\Resilience;

/**
 * How much randomness to mix into each backoff sleep.
 *
 * A deterministic backoff synchronises callers rather than spreading them. Fifty workers whose
 * calls fail at the same instant all sleep exactly 200ms, all retry at the same instant, all fail,
 * all sleep exactly 400ms — a herd arriving in tight coordinated waves, which is the worst possible
 * shape for a dependency that is trying to recover.
 *
 * Jitter randomises the sleep so the herd spreads out. In every variant the underlying delay series
 * still grows deterministically; only the sleep actually taken is randomised, so the backoff keeps
 * escalating as configured.
 */
enum Jitter
{
    /** No randomness. Every caller sleeps the full computed delay. */
    case None;

    /**
     * sleep = rand(0, delay). The best spread, and the AWS recommendation.
     *
     * A given attempt may be retried almost immediately; that is the trade for spreading the herd
     * as widely as possible across the interval.
     */
    case Full;

    /**
     * sleep = delay/2 + rand(0, delay/2). Half the delay is guaranteed, the rest is random.
     *
     * Spreads less widely than Full but never retries sooner than half the computed backoff, which
     * matters when a minimum wait between attempts is itself the point.
     */
    case Equal;
}
