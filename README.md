---
sidebar_key: resilience
tags: [php, concurrency]
---

# Resilience

Utilities for the two things that go wrong when work is spread across processes: something is not
ready yet, and something must only happen once.

[![Sponsor](https://img.shields.io/badge/Sponsor-%23ea4aaa?logo=githubsponsors&logoColor=white&labelColor=0d1117)](https://github.com/sponsors/byjg)
[![Build Status](https://github.com/byjg/php-resilience/actions/workflows/phpunit.yml/badge.svg?branch=master)](https://github.com/byjg/php-resilience/actions/workflows/phpunit.yml)
[![Opensource ByJG](https://img.shields.io/badge/opensource-byjg-success.svg)](https://opensource.byjg.com)
[![Install MCP Server](https://img.shields.io/badge/Install-MCP_Server-8A2BE2?logo=modelcontextprotocol&logoColor=white)](https://opensource.byjg.com/docs/ai/mcpserver-byjg-docs/)
[![GitHub source](https://img.shields.io/badge/Github-source-informational?logo=github)](https://github.com/byjg/php-resilience/)
[![GitHub license](https://img.shields.io/github/license/byjg/php-resilience.svg)](https://opensource.byjg.com/license/)
[![GitHub release](https://img.shields.io/github/release/byjg/php-resilience.svg)](https://github.com/byjg/php-resilience/releases/)

## Key Features

- **Exponential backoff** - poll for a value with a growing delay and a real ceiling on total wait time
- **Distributed mutex** - a named lock with owner tokens and TTL-based crash recovery
- **Pluggable lock stores** - built on an atomic compare-and-swap contract, not on best-effort caching
- **Attribute-driven** - attach either policy declaratively to a method
- **Testable timing** - injectable clock and sleep, so multi-second policies verify in virtual time

## Quick Start

Wait for something that is not there yet:

```php
use ByJG\Resilience\ExponentialBackoffWaiter;

$waiter = new ExponentialBackoffWaiter(
    initialDelayMicros: 200_000,   // 200ms
    maxTotalWaitMicros: 3_500_000, // 3.5s
    multiplier: 2.0
);

$reward = $waiter->wait(fn() => $rewardRepo->get($id));
```

Make sure something happens only once:

```php
use ByJG\Cache\Psr16\RedisCacheEngine;
use ByJG\Resilience\Lock\CacheEngineLockStore;
use ByJG\Resilience\Mutex;

$mutex = new Mutex(new CacheEngineLockStore(new RedisCacheEngine()));

$result = $mutex->execute('rebuild-report', fn() => rebuildTheReport(), ttlSeconds: 60);
```

## Documentation

- [Exponential Backoff](docs/exponential-backoff.md) - the waiter, its timing guarantees, and testing it
- [Mutex](docs/mutex.md) - acquiring, releasing, refreshing, and choosing a TTL
- [Lock Stores](docs/lock-stores.md) - the store contract, the shipped adapter, and what a lock does *not* guarantee
- [Attributes](docs/attributes.md) - `#[ExponentialBackoff]` and `#[Mutex]` with their executors

## Requirements

PHP 8.3 or later.

The `Mutex` needs a store that can perform an atomic set-if-absent. The shipped adapter covers
[byjg/cache-engine](https://github.com/byjg/php-cache-engine) engines backed by Redis or Memcached:

```bash
composer require byjg/cache-engine
```

A plain PSR-16 cache is deliberately not accepted — see
[Lock Stores](docs/lock-stores.md#why-a-plain-psr-16-cache-is-not-accepted) for why.

## Installation

```bash
composer require byjg/resilience
```

## Running Tests

```bash
vendor/bin/phpunit
```

Tests covering the lock stores need Redis and Memcached, and skip cleanly when they are absent:

```bash
docker compose up -d
vendor/bin/phpunit
docker compose down
```

----
[Open source ByJG](https://opensource.byjg.com)
