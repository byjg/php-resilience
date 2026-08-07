<?php

namespace ByJG\Resilience;

use ByJG\Resilience\Attributes\ExponentialBackoff as ExponentialBackoffAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Helper to execute methods or functions honoring the ExponentialBackoff attribute if present.
 */
class ExponentialBackoffExecutor
{
    /**
     * Execute an object or class method with attribute-aware exponential backoff.
     *
     * @param object|string $classOrObject  Instance for instance method, or class-string for static method
     * @param string $method                Method name
     * @param array $args                   Arguments passed to the method
     * @param callable(mixed):bool|null $isSuccess Optional success predicate; defaults to value !== null
     * @param ExponentialBackoffWaiter|null $waiterOverride Optional waiter to override timing (useful for tests)
     * @return mixed
     * @throws ReflectionException
     */
    public static function callWithAttributes(
        object|string $classOrObject,
        string $method,
        array $args = [],
        ?callable $isSuccess = null,
        ?ExponentialBackoffWaiter $waiterOverride = null
    ): mixed {
        $refClass = new ReflectionClass($classOrObject);
        $refMethod = $refClass->getMethod($method);

        $attr = self::findAttribute($refMethod);
        if ($attr === null) {
            // no attribute, just invoke directly
            return $refMethod->invokeArgs(is_object($classOrObject) ? $classOrObject : null, $args);
        }

        $waiter = $waiterOverride ?? self::waiterFrom($attr);

        $operation = function () use ($refMethod, $classOrObject, $args) {
            return $refMethod->invokeArgs(is_object($classOrObject) ? $classOrObject : null, $args);
        };

        return $waiter->wait($operation, $isSuccess, $attr->throwOnTimeout);
    }

    /**
     * Execute a global function or closure with attribute-aware exponential backoff.
     * If the function has no attribute, it is invoked directly.
     *
     * Note: Attributes on closures are not supported by PHP.
     *
     * @param callable $functionCallable    A string function name or [ClassName, 'method'] for static methods.
     * @param array $args                   Arguments passed to the function.
     * @param callable(mixed):bool|null $isSuccess Optional success predicate; defaults to value !== null
     * @param ExponentialBackoffWaiter|null $waiterOverride Optional waiter to override timing (useful for tests)
     * @return mixed
     * @throws ReflectionException
     */
    public static function callFunctionWithAttributes(
        callable $functionCallable,
        array $args = [],
        ?callable $isSuccess = null,
        ?ExponentialBackoffWaiter $waiterOverride = null
    ): mixed {
        if (is_string($functionCallable)) {
            $refFunction = new ReflectionFunction($functionCallable);
            $attr = self::findAttribute($refFunction);
            if ($attr === null) {
                return $refFunction->invokeArgs($args);
            }

            $waiter = $waiterOverride ?? self::waiterFrom($attr);

            $operation = function () use ($refFunction, $args) {
                return $refFunction->invokeArgs($args);
            };

            return $waiter->wait($operation, $isSuccess, $attr->throwOnTimeout);
        }

        // For other callable types (like closures), attributes aren't reflected; just invoke directly
        return $functionCallable(...$args);
    }

    /**
     * Build the waiter an attribute describes.
     *
     * Kept in one place because it was written out twice, and the duplication is exactly how the
     * jitter setting came to be silently dropped on both paths: adding a parameter to the attribute
     * meant remembering to thread it through two identical constructor calls.
     */
    private static function waiterFrom(ExponentialBackoffAttribute $attr): ExponentialBackoffWaiter
    {
        return new ExponentialBackoffWaiter(
            initialDelayMicros: $attr->initialDelayMicros,
            maxTotalWaitMicros: $attr->maxTotalWaitMicros,
            multiplier: $attr->multiplier,
            maxDelayMicros: $attr->maxDelayMicros,
            jitter: $attr->jitter
        );
    }

    private static function findAttribute(ReflectionMethod|ReflectionFunction $ref): ?ExponentialBackoffAttribute
    {
        $attributes = $ref->getAttributes(ExponentialBackoffAttribute::class);
        if (empty($attributes)) {
            return null;
        }
        /** @var ExponentialBackoffAttribute $instance */
        $instance = $attributes[0]->newInstance();
        return $instance;
    }
}
