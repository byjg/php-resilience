<?php

namespace ByJG\Resilience;

use ByJG\Resilience\Attributes\Mutex as MutexAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Helper to execute methods or functions honoring the Mutex attribute if present.
 */
class MutexExecutor
{
    /**
     * Execute an object or class method with attribute-aware mutex guard.
     *
     * @param object|class-string $classOrObject Instance for instance method, or class-string for static method
     * @param string $method Method name
     * @param array $args Arguments passed to the method
     * @param Mutex $mutex The mutex instance to use (must be constructed with a PSR-16 cache store)
     * @param ExponentialBackoffWaiter|null $waiterOverride Optional waiter to override timing (useful for tests)
     * @return mixed|null Returns method result; if lock not acquired and throwOnTimeout=false, returns null.
     * @throws ReflectionException
     */
    public static function callWithAttributes(
        object|string $classOrObject,
        string $method,
        Mutex $mutex,
        array $args = [],
        ?ExponentialBackoffWaiter $waiterOverride = null
    ): mixed {
        $refClass = new ReflectionClass($classOrObject);
        $refMethod = $refClass->getMethod($method);

        $attr = self::findAttribute($refMethod);
        if ($attr === null) {
            return $refMethod->invokeArgs(is_object($classOrObject) ? $classOrObject : null, $args);
        }

        $token = $mutex->acquire(
            name: $attr->name,
            ttlSeconds: $attr->ttlSeconds,
            waiter: $waiterOverride,
            throwOnTimeout: $attr->throwOnTimeout
        );

        if (!is_string($token)) {
            // Not acquired and throwOnTimeout=false
            return null;
        }

        try {
            return $refMethod->invokeArgs(is_object($classOrObject) ? $classOrObject : null, $args);
        } finally {
            $mutex->release($attr->name, $token);
        }
    }

    /**
     * Execute a global function with attribute-aware mutex guard.
     * If the function has no attribute, it is invoked directly.
     *
     * Note: Attributes on closures are not supported by PHP.
     *
     * @param callable $functionCallable A string function name or [ClassName, 'method'] for static methods.
     * @param array $args Arguments passed to the function.
     * @param Mutex $mutex The mutex instance to use.
     * @param ExponentialBackoffWaiter|null $waiterOverride Optional waiter to override timing (useful for tests)
     * @return mixed|null
     * @throws ReflectionException
     */
    public static function callFunctionWithAttributes(
        callable $functionCallable,
        Mutex $mutex,
        array $args = [],
        ?ExponentialBackoffWaiter $waiterOverride = null
    ): mixed {
        if (is_string($functionCallable)) {
            $refFunction = new ReflectionFunction($functionCallable);
            $attr = self::findAttribute($refFunction);
            if ($attr === null) {
                return $refFunction->invokeArgs($args);
            }

            $token = $mutex->acquire(
                name: $attr->name,
                ttlSeconds: $attr->ttlSeconds,
                waiter: $waiterOverride,
                throwOnTimeout: $attr->throwOnTimeout
            );

            if (!is_string($token)) {
                return null;
            }

            try {
                return $refFunction->invokeArgs($args);
            } finally {
                $mutex->release($attr->name, $token);
            }
        }

        // For other callable types (like closures), attributes aren't reflected; just invoke directly
        return $functionCallable(...$args);
    }

    private static function findAttribute(ReflectionMethod|ReflectionFunction $ref): ?MutexAttribute
    {
        $attributes = $ref->getAttributes(MutexAttribute::class);
        if (empty($attributes)) {
            return null;
        }
        /** @var MutexAttribute $instance */
        $instance = $attributes[0]->newInstance();
        return $instance;
    }
}
