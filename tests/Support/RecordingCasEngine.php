<?php

namespace Tests\Support;

use ByJG\Cache\CompareAndSwapInterface;
use DateInterval;

/**
 * Records every compare-and-swap call so tests can assert what the adapter forwarded, without
 * needing a running Redis or Memcached. Behaves as a correct in-process store otherwise.
 */
class RecordingCasEngine implements CompareAndSwapInterface
{
    /** @var list<array{method:string, key:string, value:mixed, ttl:DateInterval|int|null}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    private array $store = [];

    #[\Override]
    public function setIfAbsent(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->calls[] = ['method' => 'setIfAbsent', 'key' => $key, 'value' => $value, 'ttl' => $ttl];

        if (array_key_exists($key, $this->store)) {
            return false;
        }

        $this->store[$key] = $value;
        return true;
    }

    #[\Override]
    public function deleteIfEquals(string $key, mixed $value): bool
    {
        $this->calls[] = ['method' => 'deleteIfEquals', 'key' => $key, 'value' => $value, 'ttl' => null];

        if (($this->store[$key] ?? null) !== $value) {
            return false;
        }

        unset($this->store[$key]);
        return true;
    }

    #[\Override]
    public function expireIfEquals(string $key, mixed $value, DateInterval|int|null $ttl): bool
    {
        $this->calls[] = ['method' => 'expireIfEquals', 'key' => $key, 'value' => $value, 'ttl' => $ttl];

        return ($this->store[$key] ?? null) === $value;
    }
}
