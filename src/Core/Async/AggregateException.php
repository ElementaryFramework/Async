<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use Exception;
use RuntimeException;
use Throwable;

/**
 * Exception that represents multiple exceptions aggregated together.
 *
 * This exception is typically used when multiple asynchronous operations fail
 * and their individual exceptions need to be collected and reported together,
 * such as in the `any()` function when all promises reject.
 */
class AggregateException extends Exception
{
    /**
     * @var array<Throwable> The collection of inner exceptions
     */
    private array $innerExceptions;

    /**
     * Create a new AggregateException.
     *
     * @param string $message The exception message
     * @param array<Throwable> $innerExceptions Array of exceptions that were aggregated
     * @param int $code The exception code
     * @param Throwable|null $previous The previous throwable used for exception chaining
     */
    public function __construct(
        string     $message = 'Multiple exceptions occurred',
        array      $innerExceptions = [],
        int        $code = 0,
        ?Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
        $this->innerExceptions = $innerExceptions;
    }

    /**
     * Get all inner exceptions.
     *
     * @return array<Throwable> The collection of inner exceptions
     */
    public function getInnerExceptions(): array
    {
        return $this->innerExceptions;
    }

    /**
     * Get the count of inner exceptions.
     *
     * @return int The number of inner exceptions
     */
    public function getInnerExceptionCount(): int
    {
        return count($this->innerExceptions);
    }

    /**
     * Get an inner exception by index.
     *
     * @param int $index The index of the exception to retrieve
     * @return Throwable|null The exception at the specified index, or null if not found
     */
    public function getInnerException(int $index): ?Throwable
    {
        return $this->innerExceptions[$index] ?? null;
    }

    /**
     * Check if this aggregate contains any exceptions.
     *
     * @return bool True if there are inner exceptions
     */
    public function hasInnerExceptions(): bool
    {
        return !empty($this->innerExceptions);
    }

    /**
     * Get all inner exception messages as an array.
     *
     * @return array<string> Array of exception messages
     */
    public function getInnerExceptionMessages(): array
    {
        return array_map(fn(Throwable $e) => $e->getMessage(), $this->innerExceptions);
    }

    /**
     * Get a formatted string representation of all inner exceptions.
     *
     * @return string Formatted string of all inner exceptions
     */
    public function getInnerExceptionsAsString(): string
    {
        if (empty($this->innerExceptions)) {
            return 'No inner exceptions';
        }

        $messages = [];
        foreach ($this->innerExceptions as $index => $exception) {
            $messages[] = sprintf(
                '[%d] %s: %s',
                $index,
                get_class($exception),
                $exception->getMessage()
            );
        }

        return implode(PHP_EOL, $messages);
    }

    /**
     * Override the string representation to include inner exception details.
     *
     * @return string String representation of the exception
     */
    public function __toString(): string
    {
        $result = parent::__toString();

        if ($this->hasInnerExceptions()) {
            $result .= PHP_EOL . PHP_EOL . 'Inner Exceptions:' . PHP_EOL;
            $result .= $this->getInnerExceptionsAsString();
        }

        return $result;
    }

    /**
     * Create an AggregateException from an array of mixed values.
     * Non-Throwable values will be converted to RuntimeException instances.
     *
     * @param array<Throwable|string> $exceptions Array of exceptions or error messages
     * @param string $message The main exception message
     */
    public static function fromArray(array $exceptions, string $message = 'Multiple exceptions occurred'): self
    {
        $innerExceptions = [];

        foreach ($exceptions as $index => $exception) {
            if ($exception instanceof Throwable) {
                $innerExceptions[] = $exception;
            } else {
                $innerExceptions[] = new RuntimeException(
                    "Exception at index {$index}: " . $exception
                );
            }
        }

        return new self($message, $innerExceptions);
    }

    /**
     * Flatten nested AggregateExceptions into a single level.
     *
     * @return self A new AggregateException with flattened inner exceptions
     */
    public function flatten(): self
    {
        $flattened = [];

        foreach ($this->innerExceptions as $exception) {
            if ($exception instanceof self) {
                // Recursively flatten nested AggregateExceptions
                $nested = $exception->flatten();
                $flattened = array_merge($flattened, $nested->getInnerExceptions());
            } else {
                $flattened[] = $exception;
            }
        }

        return new self($this->getMessage(), $flattened, $this->getCode(), $this->getPrevious());
    }

    /**
     * Filter inner exceptions by type.
     *
     * @template T of Throwable
     * @param class-string<T> $exceptionType The type of exceptions to filter for
     * @return array<T> Array of exceptions with the specified type
     */
    public function filterByType(string $exceptionType): array
    {
        return array_filter(
            $this->innerExceptions,
            fn(Throwable $e) => $e instanceof $exceptionType
        );
    }

    /**
     * Check if any inner exception is of the specified type.
     *
     * @template T of Throwable
     * @param class-string<T> $exceptionType The type of exception to check for
     * @return bool True if any inner exception is of the specified type
     */
    public function containsType(string $exceptionType): bool
    {
        return array_any($this->innerExceptions, fn(Throwable $exception) => $exception instanceof $exceptionType);
    }
}
