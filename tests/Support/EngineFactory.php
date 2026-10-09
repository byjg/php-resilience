<?php

namespace Tests\Support;

use ByJG\Cache\CompareAndSwapInterface;
use ByJG\Cache\Psr16\BaseCacheEngine;
use ByJG\Cache\Psr16\MemcachedEngine;
use ByJG\Cache\Psr16\RedisCacheEngine;

/**
 * Builds the cache engines the lock store tests run against, from the environment.
 *
 * Locally the defaults point at docker-compose on localhost. In CI the job runs inside a container
 * and the services live elsewhere on the Docker network, addressed by service name rather than
 * loopback - so tests must come through here instead of hardcoding 127.0.0.1, otherwise they skip
 * and the build reports green having never touched a real engine.
 */
class EngineFactory
{
    public const REDIS = 'redis';
    public const MEMCACHED = 'memcached';

    public static function make(string $engine): BaseCacheEngine&CompareAndSwapInterface
    {
        return match ($engine) {
            self::REDIS => new RedisCacheEngine(
                getenv('REDIS_SERVER') ?: '127.0.0.1:6379',
                getenv('REDIS_PASSWORD') ?: ''
            ),
            self::MEMCACHED => new MemcachedEngine([getenv('MEMCACHED_SERVER') ?: '127.0.0.1:11211']),
            default => throw new \InvalidArgumentException("Unknown engine '$engine'"),
        };
    }
}
