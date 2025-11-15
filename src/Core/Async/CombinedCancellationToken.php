<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use Throwable;

/**
 * A cancellation token that combines multiple tokens and is canceled when any of them is canceled.
 *
 * This class is useful when you need to cancel an operation based on multiple conditions,
 * such as a timeout OR a manual cancellation request OR a process signal.
 */
class CombinedCancellationToken implements CancellationTokenInterface
{
    /**
     * @var list<CancellationTokenInterface> The source tokens
     */
    private array $tokens;

    /**
     * @var array<callable> Unregister functions for callbacks on source tokens
     */
    private array $unregisterFunctions = [];

    /**
     * @var bool Whether this combined token is canceled
     */
    private bool $canceled = false;

    /**
     * @var array<callable> Callbacks registered on this combined token
     */
    private array $callbacks = [];

    /**
     * {@inheritDoc}
     */
    private(set) ?string $reason = null;

    /**
     * Create a new CombinedCancellationToken.
     *
     * @param CancellationTokenInterface ...$tokens The tokens to combine
     */
    private function __construct(CancellationTokenInterface ...$tokens)
    {
        $this->tokens = array_values($tokens);
        $this->setupCancellationHandling();
    }

    /**
     * Create a combined cancellation token from multiple tokens.
     *
     * @param CancellationTokenInterface ...$tokens The tokens to combine
     */
    public static function create(CancellationTokenInterface ...$tokens): self
    {
        // Filter out tokens that can never be canceled
        $cancellableTokens = array_filter($tokens, fn($token) => $token->canBeCanceled());

        if (empty($cancellableTokens)) {
            // If no tokens can be canceled, return a never-cancel token
            return new self(CancellationToken::never());
        }

        // Check if any token is already canceled
        foreach ($cancellableTokens as $token) {
            if ($token->isCancellationRequested()) {
                return new self($token);
            }
        }

        return new self(...$cancellableTokens);
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
            throw new CancellationException($this->reason ?? 'Operation was canceled');
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
                error_log("Cancellation callback error: " . $e->getMessage());
            }

            return function (): void {
            };
        }

        $key = spl_object_id((object)$callback);
        $this->callbacks[$key] = $callback;

        return function () use ($key): void {
            unset($this->callbacks[$key]);
        };
    }

    /**
     * {@inheritDoc}
     */
    public function canBeCanceled(): bool
    {
        return array_any($this->tokens, fn($token) => $token->canBeCanceled());
    }

    /**
     * {@inheritDoc}
     */
    public function waitForCancellation(): PromiseInterface
    {
        if ($this->canceled) {
            return Promise::resolveWith(null);
        }

        if (!$this->canBeCanceled()) {
            return new Promise(); // Never resolves
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

        return self::create(...$this->tokens, ...$tokens);
    }

    /**
     * {@inheritDoc}
     */
    public function cancel(?string $reason = null): void
    {
        if ($this->canceled) {
            return;
        }

        $this->canceled = true;
        $this->reason = $reason;

        // Invoke all registered callbacks
        foreach ($this->callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                error_log("Cancellation callback error: " . $e->getMessage());
            }
        }

        // Clear callbacks and unregister from source tokens
        $this->callbacks = [];
        foreach ($this->unregisterFunctions as $unregister) {
            $unregister();
        }
        $this->unregisterFunctions = [];
    }

    /**
     * Set up cancellation handling for all source tokens.
     */
    private function setupCancellationHandling(): void
    {
        foreach ($this->tokens as $token) {
            // Check if already canceled
            if ($token->isCancellationRequested()) {
                $this->cancel($token->reason);
                return;
            }

            // Register for future cancellation
            if ($token->canBeCanceled()) {
                $unregister = $token->register(function () use ($token): void {
                    if (!$this->canceled) {
                        $this->cancel($token->reason);
                    }
                });

                $this->unregisterFunctions[] = $unregister;
            }
        }
    }

    /**
     * Clean up resources when the object is destroyed.
     */
    public function __destruct()
    {
        foreach ($this->unregisterFunctions as $unregister) {
            $unregister();
        }
    }
}
