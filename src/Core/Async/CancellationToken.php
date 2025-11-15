<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use Throwable;

/**
 * Implementation of a cancellation token that allows cooperative cancellation of asynchronous operations.
 *
 * This class provides the core functionality for cancellation tokens, including
 * - Tracking cancellation state
 * - Managing cancellation callbacks
 * - Providing cancellation reasons
 * - Creating composite tokens
 */
class CancellationToken implements CancellationTokenInterface
{
    private bool $canceled = false;
    /** @var array<callable> */
    private array $callbacks = [];
    private bool $canBeCanceled;

    /**
     * {@inheritDoc}
     */
    private(set) ?string $reason = null;

    /**
     * Create a new CancellationToken.
     *
     * @param bool $canBeCancelled Whether this token can be canceled (false creates a "never cancel" token)
     */
    public function __construct(bool $canBeCancelled = true)
    {
        $this->canBeCanceled = $canBeCancelled;
    }

    /**
     * {@inheritDoc}
     */
    public function isCancellationRequested(): bool
    {
        return $this->canceled;
    }

    /**
     * {@inheritDoc}
     */
    public function throwIfCancellationRequested(): void
    {
        if ($this->canceled) {
            throw new CancellationException($this->reason ?? 'Operation was cancelled');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function register(callable $callback): callable
    {
        // If already canceled, invoke callback immediately
        if ($this->canceled) {
            try {
                $callback();
            } catch (Throwable $e) {
                // Ignore callback errors to prevent disruption
                error_log("Cancellation callback error: " . $e->getMessage());
            }

            // Return a no-op unregister function since callback was already invoked
            return function (): void {
            };
        }

        // Store callback with a unique key for unregistration
        $key = spl_object_id((object)$callback);
        $this->callbacks[$key] = $callback;

        // Return unregister function
        return function () use ($key): void {
            unset($this->callbacks[$key]);
        };
    }

    /**
     * {@inheritDoc}
     */
    public function canBeCanceled(): bool
    {
        return $this->canBeCanceled;
    }

    /**
     * {@inheritDoc}
     */
    public function waitForCancellation(): PromiseInterface
    {
        if ($this->canceled) {
            return Promise::resolveWith(null);
        }

        if (!$this->canBeCanceled) {
            // Create a promise that never resolves
            return new Promise();
        }

        $deferred = new Deferred();

        $this->register(function () use ($deferred): void {
            $deferred->resolve();
        });

        return $deferred->promise();
    }

    /**
     * {@inheritDoc}
     */
    public function combineWith(CancellationTokenInterface ...$tokens): CancellationTokenInterface
    {
        if (empty($tokens)) {
            return $this;
        }

        return CombinedCancellationToken::create($this, ...$tokens);
    }

    /**
     * {@inheritDoc}
     */
    public function cancel(?string $reason = null): void
    {
        if ($this->canceled || !$this->canBeCanceled) {
            return;
        }

        $this->canceled = true;
        $this->reason = $reason;

        // Invoke all registered callbacks
        foreach ($this->callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                // Log but don't throw to prevent one bad callback from affecting others
                error_log("Cancellation callback error: " . $e->getMessage());
            }
        }

        // Clear callbacks to free memory
        $this->callbacks = [];
    }

    /**
     * Create a cancellation token that is never canceled.
     *
     * @return self A token that will never be canceled
     */
    public static function never(): self
    {
        return new self(false);
    }

    /**
     * Create a cancellation token that is already canceled.
     *
     * @param string|null $reason Optional reason for cancellation
     * @return self A token that is already canceled
     */
    public static function cancelled(?string $reason = null): self
    {
        $token = new self(true);
        $token->cancel($reason);
        return $token;
    }
}
