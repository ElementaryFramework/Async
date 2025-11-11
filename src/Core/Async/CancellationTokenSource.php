<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;

/**
 * A source for creating and controlling CancellationToken instances.
 *
 * This class provides a way to create cancellation tokens and control their cancellation state.
 * It follows the pattern where the token source is used by the operation initiator to create
 * and potentially cancel tokens, while the tokens themselves are passed to operations that
 * need to respond to cancellation requests.
 */
class CancellationTokenSource
{
    private CancellationToken $token;
    private bool $disposed = false;

    /**
     * Create a new CancellationTokenSource.
     */
    public function __construct()
    {
        $this->token = new CancellationToken(true);
    }

    /**
     * Get the token associated with this source.
     *
     * @return CancellationTokenInterface The cancellation token
     * @throws RuntimeException If the source has been disposed
     */
    public function getToken(): CancellationTokenInterface
    {
        $this->throwIfDisposed();
        return $this->token;
    }

    /**
     * Cancel the associated token.
     *
     * @param string|null $reason Optional reason for cancellation
     * @throws RuntimeException If the source has been disposed
     */
    public function cancel(?string $reason = null): void
    {
        $this->throwIfDisposed();
        $this->token->cancel($reason);
    }

    /**
     * Check if a cancellation has been requested.
     *
     * @return bool True if cancellation has been requested
     * @throws RuntimeException If the source has been disposed
     */
    public function isCancellationRequested(): bool
    {
        $this->throwIfDisposed();
        return $this->token->isCancellationRequested();
    }

    /**
     * Dispose of this source, preventing further use.
     *
     * After disposal, the source cannot be used and will throw exceptions on method calls.
     * The associated token will remain functional but cannot be canceled through this source.
     */
    public function dispose(): void
    {
        $this->disposed = true;
    }

    /**
     * Check if this source has been disposed of.
     *
     * @return bool True if the source has been disposed
     */
    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    /**
     * Create a cancellation token source that will be canceled after the specified timeout.
     *
     * @param float $timeout The timeout in milliseconds
     * @return self A new source that will auto-cancel after the timeout
     */
    public static function withTimeout(float $timeout): self
    {
        if ($timeout <= 0) {
            throw new InvalidArgumentException('Timeout must be greater than 0');
        }

        $source = new self();

        // Schedule cancellation after timeout
        Async::delay($timeout)->then(function () use ($source, $timeout) {
            if (!$source->isCancellationRequested() && !$source->isDisposed()) {
                $source->cancel("Timeout of $timeout milliseconds exceeded");
            }
        });

        return $source;
    }

    /**
     * Create a cancellation token source that combines multiple tokens.
     *
     * The resulting source will be canceled when any of the input tokens is canceled.
     * The returned source cannot be manually canceled - it only responds to the input tokens.
     *
     * @param CancellationTokenInterface ...$tokens The tokens to combine
     * @return self A new source that represents the combination
     */
    public static function combineTokens(CancellationTokenInterface ...$tokens): self
    {
        $source = new self();

        // Replace the source's token with a combined token
        $combinedToken = CombinedCancellationToken::create(...$tokens);

        // Use reflection to replace the token (this is an internal implementation)
        $reflection = new ReflectionClass($source);
        $tokenProperty = $reflection->getProperty('token');
        $tokenProperty->setValue($source, $combinedToken);

        return $source;
    }

    /**
     * Create a cancellation token source that will be canceled when a specific signal is received.
     *
     * @param int $signal The signal number to listen for (e.g., SIGINT, SIGTERM)
     * @return self A new source that will be canceled on signal
     */
    public static function withSignal(int $signal): self
    {
        $source = new self();

        // Register signal handler if PCNTL extension is available
        if (Async::supportsPCNTL()) {
            pcntl_signal($signal, function ($signal) use ($source) {
                if (!$source->isCancellationRequested() && !$source->isDisposed()) {
                    $source->cancel("Received signal $signal");
                }
            });
        }

        return $source;
    }

    /**
     * Create a cancellation token source that will never be canceled.
     *
     * This is useful as a default token for operations that don't need cancellation support.
     *
     * @return self A source with a token that will never be canceled
     */
    public static function never(): self
    {
        $source = new self();

        // Replace with a never-cancel token
        $reflection = new ReflectionClass($source);
        $tokenProperty = $reflection->getProperty('token');
        $tokenProperty->setValue($source, CancellationToken::never());

        return $source;
    }

    /**
     * Create a cancellation token source that is already canceled.
     *
     * @param string|null $reason Optional reason for cancellation
     * @return self A source with an already canceled token
     */
    public static function cancelled(?string $reason = null): self
    {
        $source = new self();
        $source->cancel($reason);
        return $source;
    }

    /**
     * Throw an exception if the source has been disposed.
     *
     * @throws RuntimeException If the source has been disposed
     */
    private function throwIfDisposed(): void
    {
        if ($this->disposed) {
            throw new RuntimeException('CancellationTokenSource has been disposed');
        }
    }

    /**
     * Clean up resources when the object is destroyed.
     */
    public function __destruct()
    {
        if (!$this->disposed) {
            $this->dispose();
        }
    }
}
