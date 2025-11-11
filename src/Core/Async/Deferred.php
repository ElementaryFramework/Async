<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use RuntimeException;
use Throwable;

/**
 * A Deferred represents a Promise that can be resolved or rejected manually.
 *
 * This class provides a way to create a Promise and control its resolution
 * externally. It's useful for integrating callback-based APIs with promises
 * or for cases where the promise resolution logic is complex and needs to
 * be handled outside the promise constructor.
 *
 * @template T The type of value this deferred resolves to
 */
final class Deferred
{
    /**
     * @var Promise<T> The underlying promise
     */
    private Promise $promise;

    /**
     * @var bool Whether the deferred has been resolved or rejected
     */
    private(set) bool $settled = false;

    /**
     * Get the current state of the underlying promise.
     */
    public PromiseState $state {
        get => $this->promise->state;
    }

    /**
     * Create a new Deferred.
     *
     * @param callable(): void|null $canceller Optional cancellation callback
     */
    public function __construct(?callable $canceller = null)
    {
        $this->promise = new Promise(null, $canceller);
    }

    /**
     * Get the underlying promise.
     *
     * @return PromiseInterface<T> The promise that will be resolved/rejected
     */
    public function promise(): PromiseInterface
    {
        return $this->promise;
    }

    /**
     * Resolve the deferred with a value.
     *
     * @param T|PromiseInterface<T> $value The value to resolve with
     * @throws RuntimeException If the deferred is already settled
     */
    public function resolve(mixed $value = null): void
    {
        if ($this->settled) {
            throw new RuntimeException('Deferred is already settled');
        }

        $this->settled = true;

        $this->promise->resolve($value);
    }

    /**
     * Reject the deferred with a reason.
     *
     * @param Throwable $reason The reason for rejection
     * @throws RuntimeException If the deferred is already settled
     */
    public function reject(Throwable $reason): void
    {
        if ($this->settled) {
            throw new RuntimeException('Deferred is already settled');
        }

        $this->settled = true;

        $this->promise->reject($reason);
    }

    /**
     * Cancel the underlying promise.
     *
     * This will reject the promise with a CancellationException if it's still pending.
     */
    public function cancel(): void
    {
        if (!$this->settled) {
            $this->promise->cancel();
            $this->settled = true;
        }
    }

    /**
     * Create a `Deferred` that resolves with the given value.
     *
     * @template U
     * @param U|PromiseInterface<U> $value The value to resolve with
     * @return static<U>
     */
    public static function resolved(mixed $value = null): self
    {
        $deferred = new self();
        $deferred->resolve($value);
        return $deferred;
    }

    /**
     * Create a `Deferred` that rejects with the given reason.
     *
     * @param Throwable $reason The reason for rejection
     * @return static<never>
     */
    public static function rejected(Throwable $reason): self
    {
        $deferred = new self();
        $deferred->reject($reason);
        return $deferred;
    }

    /**
     * Create a `Deferred` from a callback-based function.
     *
     * This helper method makes it easy to convert callback-based APIs to promises.
     *
     * @template U
     * @param callable(callable(U): void, callable(Throwable): void): void $executor
     *        Function that receives resolve and reject callbacks
     * @return static<U>
     */
    public static function fromCallback(callable $executor): self
    {
        $deferred = new self();

        try {
            $executor(
                fn(mixed $value) => $deferred->resolve($value),
                fn(Throwable $reason) => $deferred->reject($reason)
            );
        } catch (Throwable $e) {
            $deferred->reject($e);
        }

        return $deferred;
    }
}
