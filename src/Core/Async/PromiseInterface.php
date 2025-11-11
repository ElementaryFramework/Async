<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use RuntimeException;
use Throwable;

/**
 * Interface for Promise objects that represent eventual completion or failure of asynchronous operations.
 *
 * This interface follows the Promise/A+ specification and provides methods for:
 * - Chaining operations with then()
 * - Handling errors with catch()
 * - Cleanup operations with finally()
 * - Cancellation support with cancel()
 *
 * @template-covariant T The type of value this promise resolves to
 */
interface PromiseInterface
{
    /**
     * Gets the current state of the promise.
     */
    public PromiseState $state {
        get;
    }

    /**
     * Attaches callbacks for the resolution and/or rejection of the Promise.
     *
     * @template TResult1 The type of the value returned by the fulfillment callback
     * @template TResult2 The type of the value returned by the rejection callback
     *
     * @param callable(T|null): (TResult1|PromiseInterface<TResult1>)|null $onFulfilled
     *        Called when the promise is fulfilled. Return value becomes the resolved value
     *        of the returned promise. If it returns a promise, the returned promise will
     *        resolve/reject based on the returned promise.
     * @param callable(Throwable): (TResult2|PromiseInterface<TResult2>)|null $onRejected
     *        Called when the promise is rejected. Return value becomes the resolved value
     *        of the returned promise unless it throws an exception.
     *
     * @return PromiseInterface<($onFulfilled is callable ? TResult1 : T)|($onRejected is callable ? TResult2 : T)> A new promise that resolves based on the handlers
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): PromiseInterface;

    /**
     * Attaches a rejection handler callback to the promise.
     * This is a shortcut for `then(null, $onRejected)`.
     *
     * @template TResult The type of the value returned by the rejection callback
     *
     * @param callable(Throwable): (TResult|PromiseInterface<TResult>) $onRejected
     *        Called when the promise is rejected. Return value becomes the resolved value
     *        of the returned promise unless it throws an exception.
     *
     * @return PromiseInterface<T|TResult> A new promise that resolves based on the handler
     */
    public function catch(callable $onRejected): PromiseInterface;

    /**
     * Attaches a callback always executed when the Promise is settled (fulfilled or rejected).
     * The callback receives no arguments, and its return value is ignored.
     *
     * @param callable(): void $onFinally Called when the promise settles
     *
     * @return PromiseInterface<T|null> A new promise that resolves/rejects with the same value/reason
     */
    public function finally(callable $onFinally): PromiseInterface;

    /**
     * Cancels the promise if it is still pending.
     * Once canceled, the promise will be rejected with a CancellationException.
     *
     * Calling cancel on an already settled promise has no effect.
     *
     * @return void
     */
    public function cancel(): void;

    /**
     * Check if the promise is settled (either fulfilled or rejected).
     *
     * @return bool True if the promise is settled
     */
    public function isSettled(): bool;

    /**
     * Check if the promise is fulfilled.
     *
     * @return bool True if the promise is fulfilled
     */
    public function isFulfilled(): bool;

    /**
     * Check if the promise is rejected.
     *
     * @return bool True if the promise is rejected
     */
    public function isRejected(): bool;

    /**
     * Check if the promise is pending.
     *
     * @return bool True if the promise is pending
     */
    public function isPending(): bool;

    /**
     * Get the resolved value of the promise.
     * Only available if the promise is fulfilled.
     *
     * @return T|null The resolved value
     * @throws RuntimeException If the promise is not fulfilled
     */
    public function unwrap(): mixed;

    /**
     * Get the rejection reason of the promise.
     * Only available if the promise is rejected.
     *
     * @return Throwable|null The rejection reason
     * @throws RuntimeException If the promise is not rejected
     */
    public function getReason(): ?Throwable;
}
