<?php

namespace ByJG\Resilience\Attributes;

use Attribute;

/**
 * Attribute to mark a function or method to be executed using ExponentialBackoffWaiter.
 *
 * Note: PHP attributes do not change execution automatically; use
 * ByJG\Resilience\ExponentialBackoffExecutor::callWithAttributes() to invoke.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
class ExponentialBackoff
{
    public function __construct(
        public int $initialDelayMicros = 200_000,
        public int $maxTotalWaitMicros = 3_500_000,
        public float $multiplier = 2.0,
        public ?int $maxDelayMicros = null,
        public bool $throwOnTimeout = true,
    ) {
    }
}
