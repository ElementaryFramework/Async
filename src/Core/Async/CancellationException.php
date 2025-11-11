<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use Exception;
use Throwable;

/**
 * Exception thrown when a promise or asynchronous operation is canceled.
 *
 * This exception is used to signal that an operation was intentionally
 * canceled before it could complete, either by explicit cancellation
 * or by timeout/other cancellation conditions.
 */
class CancellationException extends Exception
{
    /**
     * Create a new CancellationException.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param Throwable|null $previous The previous throwable used for exception chaining
     */
    public function __construct(
        string     $message = 'Operation was cancelled',
        int        $code = 0,
        ?Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a CancellationException for timeout scenarios.
     *
     * @param float $timeout The timeout duration in milliseconds
     * @param Throwable|null $previous The previous throwable used for exception chaining
     */
    public static function timeout(float $timeout, ?Throwable $previous = null): self
    {
        return new self(
            "Operation timed out after $timeout milliseconds",
            0,
            $previous
        );
    }

    /**
     * Create a CancellationException for manual cancellation.
     *
     * @param string $reason The reason for cancellation
     * @param Throwable|null $previous The previous throwable used for exception chaining
     */
    public static function manual(string $reason = 'Manually cancelled', ?Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }

    /**
     * Create a CancellationException for signal-based cancellation.
     *
     * @param int $signal The signal number that triggered cancellation
     * @param Throwable|null $previous The previous throwable used for exception chaining
     */
    public static function signal(int $signal, ?Throwable $previous = null): self
    {
        return new self(
            "Operation cancelled by signal $signal",
            $signal,
            $previous
        );
    }
}
