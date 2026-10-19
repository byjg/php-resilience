<?php

namespace ByJG\Resilience;

use ByJG\Resilience\Attributes\Mutex as MutexAttribute;
use Closure;
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
     * Without a Mutex attribute on the method, it is invoked directly and the mutex is not used.
     *
     * @param object|class-string $classOrObject Instance for instance method, or class-string for static method
     * @param string $method Method name
     * @param Mutex $mutex The mutex to lock with (backed by the LockStoreInterface it was constructed with)
     * @param array $args Arguments passed to the method
     * @param ExponentialBackoffWaiter|null $waiterOverride Optional waiter to override the acquire timing
     *        (useful for tests); when null, Mutex::acquire() uses a default ExponentialBackoffWaiter
     * @return mixed Returns the method result; null without running the method if the lock was not
     *         acquired and the attribute's throwOnTimeout is false.
     * @throws ReflectionException When the method does not exist
     * @throws \ByJG\Resilience\Exceptions\TimeoutException When the lock was not acquired and the attribute's throwOnTimeout is true
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
     * Execute any callable with attribute-aware mutex guard.
     * If the callable has no attribute, it is invoked directly.
     *
     * The attribute is read from whatever the callable points to: a function name ('fn'), a static
     * method ('ClassName::method' or [ClassName::class, 'method']), an instance method
     * ([$object, 'method'] or $object->method(...)), a closure declared with the attribute
     * (#[Mutex(...)] fn() => ...) or an invokable object (attribute on __invoke).
     *
     * @param callable $functionCallable Any callable.
     * @param Mutex $mutex The mutex to lock with (backed by the LockStoreInterface it was constructed with)
     * @param array $args Arguments passed to the function.
     * @param ExponentialBackoffWaiter|null $waiterOverride Optional waiter to override the acquire timing
     *        (useful for tests); when null, Mutex::acquire() uses a default ExponentialBackoffWaiter
     * @return mixed Returns the function result; null without running the function if the lock was not
     *         acquired and the attribute's throwOnTimeout is false.
     * @throws ReflectionException
     * @throws \ByJG\Resilience\Exceptions\TimeoutException When the lock was not acquired and the attribute's throwOnTimeout is true
     */
    public static function callFunctionWithAttributes(
        callable $functionCallable,
        Mutex $mutex,
        array $args = [],
        ?ExponentialBackoffWaiter $waiterOverride = null
    ): mixed {
        // Closure::fromCallable() normalises every callable form, and reflecting the closure exposes
        // the attributes of the function or method it wraps.
        $refFunction = new ReflectionFunction(Closure::fromCallable($functionCallable));
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
