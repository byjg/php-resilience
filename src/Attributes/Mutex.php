<?php

namespace ByJG\Resilience\Attributes;

use Attribute;

/**
 * Attribute to guard a function or method execution with a Mutex lock.
 *
 * Note: Use ByJG\Resilience\MutexExecutor to invoke attribute-aware calls.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
class Mutex
{
    public function __construct(
        public string $name,
        public int $ttlSeconds = 30,
        public bool $throwOnTimeout = true,
    ) {
    }
}
