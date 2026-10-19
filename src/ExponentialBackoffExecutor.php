<?php

namespace ByJG\Resilience;

use ByJG\Resilience\Attributes\ExponentialBackoff as ExponentialBackoffAttribute;
use Closure;
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
     * Without an ExponentialBackoff attribute on the method, it is invoked once directly and
     * $isSuccess is not used.
     *
     * @param object|class-string $classOrObject  Instance for instance method, or class-string for static method
     * @param string $method                Method name
     * @param array $args                   Arguments passed to the method
     * @param callable(mixed):bool|null $isSuccess Optional success predicate; defaults to value !== null
     * @param ExponentialBackoffWaiter|null $waiterOverride Optional waiter that replaces the one built from
     *        the attribute (useful for tests); the attribute's throwOnTimeout still applies
     * @return mixed The successful result, or the last result if timed out and the attribute's
     *         throwOnTimeout is false
     * @throws ReflectionException When the method does not exist
     * @throws \ByJG\Resilience\Exceptions\TimeoutException When timed out and the attribute's throwOnTimeout is true
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
     * Execute any callable with attribute-aware exponential backoff.
     * If the callable has no attribute, it is invoked once directly.
     *
     * The attribute is read from whatever the callable points to: a function name ('fn'), a static
     * method ('ClassName::method' or [ClassName::class, 'method']), an instance method
     * ([$object, 'method'] or $object->method(...)), a closure declared with the attribute
     * (#[ExponentialBackoff(...)] fn() => ...) or an invokable object (attribute on __invoke).
     *
     * @param callable $functionCallable    Any callable.
     * @param array $args                   Arguments passed to the function.
     * @param callable(mixed):bool|null $isSuccess Optional success predicate; defaults to value !== null
     * @param ExponentialBackoffWaiter|null $waiterOverride Optional waiter that replaces the one built from
     *        the attribute (useful for tests); the attribute's throwOnTimeout still applies
     * @return mixed The successful result, or the last result if timed out and the attribute's
     *         throwOnTimeout is false
     * @throws ReflectionException
     * @throws \ByJG\Resilience\Exceptions\TimeoutException When timed out and the attribute's throwOnTimeout is true
     */
    public static function callFunctionWithAttributes(
        callable $functionCallable,
        array $args = [],
        ?callable $isSuccess = null,
        ?ExponentialBackoffWaiter $waiterOverride = null
    ): mixed {
        // Closure::fromCallable() normalises every callable form, and reflecting the closure exposes
        // the attributes of the function or method it wraps.
        $refFunction = new ReflectionFunction(Closure::fromCallable($functionCallable));
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
