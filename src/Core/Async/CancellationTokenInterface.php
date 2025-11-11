<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

/**
 * Interface for cancellation tokens that allow cooperative cancellation of asynchronous operations.
 *
 * A CancellationToken provides a way to signal that an operation should be canceled.
 * It supports registering callbacks that will be invoked when cancellation is requested
 * and provides methods to check the current cancellation state.
 *
 * This interface follows the cooperative cancellation pattern where operations
 * periodically check the token and voluntarily terminate when cancellation is requested.
 */
interface CancellationTokenInterface
{
    /**
     * Get the cancellation reason, if any.
     *
     * @return string|null The reason for cancellation, or null if not canceled or no reason provided
     */
    public ?string $reason {
        get;
    }

    /**
     * Check if a cancellation has been requested.
     *
     * @return bool True if cancellation has been requested
     */
    public function isCancellationRequested(): bool;

    /**
     * Throw a CancellationException if a cancellation has been requested.
     *
     * This is a convenience method that operations can call to automatically
     * throw an exception when cancellation is detected.
     *
     * @throws CancellationException If cancellation has been requested
     */
    public function throwIfCancellationRequested(): void;

    /**
     * Register a callback to be invoked when cancellation is requested.
     *
     * The callback will be invoked immediately if cancellation has already been requested.
     * Multiple callbacks can be registered, and they will all be invoked when cancellation occurs.
     *
     * @param callable(): void $callback The callback to invoke on cancellation
     * @return callable(): void A function that can be called to unregister the callback
     */
    public function register(callable $callback): callable;

    /**
     * Check if this is a "never cancel" token that will never be canceled.
     *
     * @return bool True if this token is never canceled
     */
    public function canBeCanceled(): bool;

    /**
     * Wait for cancellation to be requested.
     *
     * This method will return immediately if cancellation has already been requested,
     * otherwise it will suspend the current execution until cancellation is requested.
     *
     * @return PromiseInterface<null> A promise that resolves when cancellation is requested
     */
    public function waitForCancellation(): PromiseInterface;

    /**
     * Combine this token with other tokens to create a composite token.
     *
     * The resulting token will be canceled when any of the source tokens is canceled.
     *
     * @param CancellationTokenInterface ...$tokens Additional tokens to combine with
     * @return CancellationTokenInterface A new token that represents the combination
     */
    public function combineWith(CancellationTokenInterface ...$tokens): CancellationTokenInterface;
}
