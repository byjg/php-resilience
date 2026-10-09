<?php

namespace ByJG\Resilience\Attributes;

use Attribute;
use ByJG\Resilience\Jitter;

/**
 * Attribute to mark a function or method to be executed using ExponentialBackoffWaiter.
 *
 * Note: PHP attributes do not change execution automatically; use
 * ByJG\Resilience\ExponentialBackoffExecutor::callWithAttributes() to invoke.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
class ExponentialBackoff
{
    /**
     * The waiter's randomFn has no equivalent here - attribute arguments must be constant
     * expressions, and there is no way to write a callable as one. The default random_int is what
     * production wants anyway; a test that needs deterministic jitter passes a fully configured
     * waiter through the executor's waiterOverride instead.
     */
    public function __construct(
        public int $initialDelayMicros = 200_000,
        public int $maxTotalWaitMicros = 3_500_000,
        public float $multiplier = 2.0,
        public ?int $maxDelayMicros = null,
        public bool $throwOnTimeout = true,
        public Jitter $jitter = Jitter::None,
    ) {
    }
}
