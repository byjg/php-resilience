<?php

namespace Tests;

use ByJG\Resilience\Attributes\ExponentialBackoff;
use ByJG\Resilience\Attributes\Mutex as MutexAttribute;
use ByJG\Resilience\ExponentialBackoffExecutor;
use ByJG\Resilience\ExponentialBackoffWaiter;
use ByJG\Resilience\Mutex;
use ByJG\Resilience\MutexExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArrayLockStore;

/**
 * Every callable form points at the same recorder, so a test can tell from the outside whether the
 * executor honoured the attribute: the backoff retries until the third call succeeds, and the mutex
 * is held while the call runs.
 */
class CallableFormsTarget
{
    public const LOCK_KEY = 'resilience:mutex:callable-forms';

    public static int $calls = 0;
    public static ?ArrayLockStore $store = null;
    /** @var list<?string> Lock owner observed during each call */
    public static array $ownerDuringCall = [];

    public static function reset(?ArrayLockStore $store = null): void
    {
        self::$calls = 0;
        self::$store = $store;
        self::$ownerDuringCall = [];
    }

    public static function record(): ?string
    {
        self::$calls++;
        self::$ownerDuringCall[] = self::$store?->owner(self::LOCK_KEY);
        return self::$calls >= 3 ? 'OK' : null;
    }

    #[ExponentialBackoff]
    #[MutexAttribute(name: 'callable-forms')]
    public static function staticAttributed(): ?string
    {
        return self::record();
    }

    #[ExponentialBackoff]
    #[MutexAttribute(name: 'callable-forms')]
    public function instanceAttributed(): ?string
    {
        return self::record();
    }

    public static function staticPlain(): ?string
    {
        return self::record();
    }

    public function instancePlain(): ?string
    {
        return self::record();
    }
}

class InvokableAttributed
{
    #[ExponentialBackoff]
    #[MutexAttribute(name: 'callable-forms')]
    public function __invoke(): ?string
    {
        return CallableFormsTarget::record();
    }
}

class InvokablePlain
{
    public function __invoke(): ?string
    {
        return CallableFormsTarget::record();
    }
}

#[ExponentialBackoff]
#[MutexAttribute(name: 'callable-forms')]
function callableFormsAttributedFunction(): ?string
{
    return CallableFormsTarget::record();
}

function callableFormsPlainFunction(): ?string
{
    return CallableFormsTarget::record();
}

class CallableFormsAttributeTest extends TestCase
{
    public static function attributedCallables(): array
    {
        return [
            'function name' => ['Tests\callableFormsAttributedFunction'],
            'Class::method string' => [CallableFormsTarget::class . '::staticAttributed'],
            '[class, method]' => [[CallableFormsTarget::class, 'staticAttributed']],
            '[object, method]' => [[new CallableFormsTarget(), 'instanceAttributed']],
            'first-class callable' => [(new CallableFormsTarget())->instanceAttributed(...)],
            'closure' => [#[ExponentialBackoff] #[MutexAttribute(name: 'callable-forms')] fn() => CallableFormsTarget::record()],
            'invokable object' => [new InvokableAttributed()],
        ];
    }

    public static function plainCallables(): array
    {
        return [
            'function name' => ['Tests\callableFormsPlainFunction'],
            'Class::method string' => [CallableFormsTarget::class . '::staticPlain'],
            '[class, method]' => [[CallableFormsTarget::class, 'staticPlain']],
            '[object, method]' => [[new CallableFormsTarget(), 'instancePlain']],
            'closure' => [fn() => CallableFormsTarget::record()],
            'invokable object' => [new InvokablePlain()],
        ];
    }

    private function fastWaiter(): ExponentialBackoffWaiter
    {
        $now = 1000.0;
        return new ExponentialBackoffWaiter(
            initialDelayMicros: 200_000,
            maxTotalWaitMicros: 3_500_000,
            multiplier: 2.0,
            sleepFn: function (int $micros) use (&$now): void { $now += (float)$micros / 1_000_000.0; },
            timeFn: function () use (&$now): float { return $now; }
        );
    }

    #[DataProvider('attributedCallables')]
    public function testBackoffAttributeIsHonouredForEveryCallableForm(callable $callable): void
    {
        CallableFormsTarget::reset();

        $result = ExponentialBackoffExecutor::callFunctionWithAttributes($callable, waiterOverride: $this->fastWaiter());

        $this->assertSame('OK', $result);
        $this->assertSame(3, CallableFormsTarget::$calls, 'The attribute was not applied: no retries happened');
    }

    #[DataProvider('plainCallables')]
    public function testBackoffWithoutAttributeRunsOnce(callable $callable): void
    {
        CallableFormsTarget::reset();

        $result = ExponentialBackoffExecutor::callFunctionWithAttributes($callable, waiterOverride: $this->fastWaiter());

        $this->assertNull($result);
        $this->assertSame(1, CallableFormsTarget::$calls);
    }

    #[DataProvider('attributedCallables')]
    public function testMutexAttributeIsHonouredForEveryCallableForm(callable $callable): void
    {
        $store = new ArrayLockStore();
        CallableFormsTarget::reset($store);

        MutexExecutor::callFunctionWithAttributes($callable, mutex: new Mutex($store));

        $this->assertSame(1, CallableFormsTarget::$calls);
        $this->assertNotNull(CallableFormsTarget::$ownerDuringCall[0], 'The lock was not held during the call');
        $this->assertNull($store->owner(CallableFormsTarget::LOCK_KEY), 'The lock was not released');
    }

    #[DataProvider('plainCallables')]
    public function testMutexWithoutAttributeRunsWithoutLocking(callable $callable): void
    {
        $store = new ArrayLockStore();
        CallableFormsTarget::reset($store);

        MutexExecutor::callFunctionWithAttributes($callable, mutex: new Mutex($store), args: []);

        $this->assertSame([null], CallableFormsTarget::$ownerDuringCall);
    }
}
