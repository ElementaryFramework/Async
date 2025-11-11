<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use ReflectionFunction;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * Core Promise implementation that represents the eventual completion or failure of asynchronous operations.
 *
 * This class follows the Promise/A+ specification and provides:
 * - Immutable state transitions (pending -> fulfilled/rejected)
 * - Proper error handling and propagation
 * - Chaining support with then(), catch(), finally()
 * - Cancellation support
 * - Memory-efficient callback storage
 *
 * @template T The type of value this promise resolves to
 * @implements PromiseInterface<T>
 */
class Promise implements PromiseInterface
{
    private(set) PromiseState $state = PromiseState::PENDING;
    /** @var T|null */
    private mixed $value = null;
    private ?Throwable $reason = null;
    /** @var array<callable(T): mixed> */
    private array $fulfillmentCallbacks = [];
    /** @var array<callable(Throwable): mixed> */
    private array $rejectionCallbacks = [];
    /** @var callable(): void|null */
    private mixed $canceller;

    /**
     * Static cache for settled promises to avoid recreating them.
     * @var WeakMap<PromiseInterface<T>, PromiseInterface<T>>
     */
    private static WeakMap $settledPromises;

    /**
     * Create a new Promise.
     *
     * @param callable(callable(T): void, callable(Throwable): void): void|null $executor
     *        Function that receives resolve and reject callbacks to control the promise
     * @param callable(): void|null $canceller
     *        Optional function called when the promise is canceled
     */
    public function __construct(?callable $executor = null, ?callable $canceller = null)
    {
        if (!isset(self::$settledPromises)) {
            self::$settledPromises = new WeakMap();
        }

        $this->canceller = $canceller;

        if ($executor !== null) {
            try {
                $executor(
                    fn(mixed $value) => $this->resolve($value),
                    fn(Throwable $reason) => $this->reject($reason)
                );
            } catch (Throwable $e) {
                $this->reject($e);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): PromiseInterface
    {
        if ($this->state->isSettled()) {
            return $this->handleSettledThen($onFulfilled, $onRejected);
        }

        // Create a new promise for the chained result
        $promise = new self();

        $this->fulfillmentCallbacks[] = function (mixed $value) use ($promise, $onFulfilled): void {
            $this->handleCallback($promise, $onFulfilled, $value, true);
        };

        $this->rejectionCallbacks[] = function (Throwable $reason) use ($promise, $onRejected): void {
            $this->handleCallback($promise, $onRejected, $reason, false);
        };

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function catch(callable $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    /**
     * {@inheritdoc}
     */
    public function finally(callable $onFinally): PromiseInterface
    {
        return $this->then(
            function (mixed $value) use ($onFinally): mixed {
                $onFinally();
                return $value;
            },
            function (Throwable $reason) use ($onFinally): never {
                $onFinally();
                throw $reason;
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(): void
    {
        if ($this->state->isSettled()) {
            return;
        }

        if ($this->canceller !== null) {
            try {
                ($this->canceller)();
            } catch (Throwable $e) {
                $this->reject($e);
                return;
            }
        }

        $this->reject(new CancellationException('Promise was cancelled'));
    }

    /**
     * {@inheritdoc}
     */
    public function isSettled(): bool
    {
        return $this->state->isSettled();
    }

    /**
     * {@inheritdoc}
     */
    public function isFulfilled(): bool
    {
        return $this->state->isFulfilled();
    }

    /**
     * {@inheritdoc}
     */
    public function isRejected(): bool
    {
        return $this->state->isRejected();
    }

    /**
     * {@inheritdoc}
     */
    public function isPending(): bool
    {
        return $this->state->isPending();
    }

    /**
     * {@inheritdoc}
     */
    public function unwrap(): mixed
    {
        if (!$this->state->isFulfilled()) {
            throw new RuntimeException('Promise is not fulfilled');
        }

        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function getReason(): ?Throwable
    {
        if (!$this->state->isRejected()) {
            throw new RuntimeException('Promise is not rejected');
        }

        return $this->reason;
    }

    /**
     * Resolve the promise with a value.
     *
     * @param T|PromiseInterface<T>|null $value The value to resolve with
     * @internal This method is intended for internal use by Deferred and other async components
     */
    public function resolve(mixed $value): void
    {
        if ($this->state->isSettled()) {
            return;
        }

        // Handle thenable values (promises)
        if ($value instanceof PromiseInterface) {
            $value->then(
                fn(mixed $v) => $this->resolve($v),
                fn(Throwable $r) => $this->reject($r)
            );
            return;
        }

        $this->state = PromiseState::FULFILLED;
        $this->value = $value;

        $this->invokeCallbacks($this->fulfillmentCallbacks, $value);
        $this->clearCallbacks();
    }

    /**
     * Reject the promise with a reason.
     *
     * @param Throwable $reason The reason for rejection
     * @internal This method is intended for internal use by Deferred and other async components
     */
    public function reject(Throwable $reason): void
    {
        if ($this->state->isSettled()) {
            return;
        }

        $this->state = PromiseState::REJECTED;
        $this->reason = $reason;

        $this->invokeCallbacks($this->rejectionCallbacks, $reason);
        $this->clearCallbacks();
    }

    /**
     * Handle then() calls on already settled promises.
     *
     * @template U1
     * @template U2
     *
     * @param null|callable(T|null): (U1|PromiseInterface<U1>) $onFulfilled
     * @param null|callable(Throwable): (U2|PromiseInterface<U2>|never) $onRejected
     * @return PromiseInterface<U1|U2>
     */
    private function handleSettledThen(?callable $onFulfilled, ?callable $onRejected): PromiseInterface
    {
        if ($this->state->isFulfilled()) {
            if ($onFulfilled === null) {
                return $this;
            }

            try {
                $result = $onFulfilled($this->value);
                return self::resolveWith($result);
            } catch (Throwable $e) {
                return self::rejectWith($e);
            }
        } else {
            if ($onRejected === null) {
                return $this;
            }

            $this->reason ??= new RuntimeException('Promise was rejected');

            try {
                $reflection = new ReflectionFunction($onRejected(...));
                $parameters = $reflection->getParameters();
                if (empty($parameters) || ($exceptionType = $parameters[0]->getType()) === null || $this->reason instanceof ($exceptionType->getName())) {
                    $result = $onRejected($this->reason);
                    return self::resolveWith($result);
                } else {
                    // Ignore the rejection call when the exception type does not match
                    return $this;
                }
            } catch (Throwable $e) {
                return self::rejectWith($e);
            }
        }
    }

    /**
     * Handle callback execution and promise chaining.
     *
     * @template U
     * @param Promise<U> $promise
     * @param null|callable(($isFulfillment is true ? T : Throwable)): U $callback
     * @param ($callback is callable ? T : ($isFulfillment is true ? U : Throwable)) $value
     * @param bool $isFulfillment
     */
    private function handleCallback(
        Promise   $promise,
        ?callable $callback,
        mixed     $value,
        bool      $isFulfillment
    ): void
    {
        if ($callback === null) {
            if ($isFulfillment) {
                /** @var U|null $value */
                $promise->resolve($value);
            } else {
                $promise->reject($value);
            }
            return;
        }

        try {
            $result = $callback($value);
            $promise->resolve($result);
        } catch (Throwable $e) {
            $promise->reject($e);
        }
    }

    /**
     * Invoke an array of callbacks with a value.
     *
     * @param array<callable(T|Throwable): void|callable(T): mixed> $callbacks
     * @param T|Throwable|null $value
     */
    private function invokeCallbacks(array $callbacks, mixed $value): void
    {
        foreach ($callbacks as $callback) {
            try {
                /** @var callable $callback */
                $callback($value);
            } catch (Throwable $e) {
                // Callback errors should not affect the promise state
                // In a full implementation, this could be reported to a global handler
                error_log("Unhandled promise callback error: " . $e->getMessage());
            }
        }
    }

    /**
     * Clear all callback arrays to free memory.
     */
    private function clearCallbacks(): void
    {
        $this->fulfillmentCallbacks = [];
        $this->rejectionCallbacks = [];
    }

    /**
     * Create a promise that resolves with the given value.
     *
     * @template U
     * @param U|PromiseInterface<U> $value The value to resolve with
     * @return PromiseInterface<U>
     */
    public static function resolveWith(mixed $value = null): PromiseInterface
    {
        if ($value instanceof PromiseInterface) {
            return $value;
        }

        $promise = new self();
        $promise->resolve($value);
        return $promise;
    }

    /**
     * Create a promise that rejects with the given reason.
     *
     * @param Throwable $reason The reason for rejection
     * @return PromiseInterface<never>
     */
    public static function rejectWith(Throwable $reason): PromiseInterface
    {
        $promise = new self();
        $promise->reject($reason);
        return $promise;
    }
}
