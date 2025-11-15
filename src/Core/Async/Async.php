<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use Closure;
use Fiber;
use InvalidArgumentException;
use Throwable;

/**
 * Main entry point for the ElementaryFramework Async library.
 *
 * This class provides static methods for creating and managing asynchronous operations
 * using PHP Fibers and promises. It serves as a convenient facade for the underlying
 * async primitives and event loop functionality.
 *
 * Features:
 * - Promise/A+ compatible promises
 * - Fiber-based async execution
 * - Event loop management
 * - Cancellation support
 * - Timer utilities
 * - Promise combinators (all, race, any, etc.)
 * - Concurrency control
 * - Retry mechanisms
 */
final class Async
{
    /**
     * Prevent instantiation of this utility class.
     */
    private function __construct()
    {
    }

    /**
     * Execute a callable asynchronously using the event loop.
     *
     * @template T
     * @param callable(): T $callable The callable to execute
     * @param CancellationTokenInterface|null $cancellationToken Optional cancellation token
     * @return PromiseInterface<T>
     */
    public static function run(callable $callable, ?CancellationTokenInterface $cancellationToken = null): PromiseInterface
    {
        return EventLoop::getInstance()->async($callable, $cancellationToken);
    }

    /**
     * Create a promise that resolves with the given value.
     *
     * @template T
     * @param T|PromiseInterface<T> $value The value to resolve with
     * @return PromiseInterface<T>
     */
    public static function resolve(mixed $value = null): PromiseInterface
    {
        return Promise::resolveWith($value);
    }

    /**
     * Create a promise that rejects with the given reason.
     *
     * @param Throwable $reason The reason for rejection
     * @return PromiseInterface<never>
     */
    public static function reject(Throwable $reason): PromiseInterface
    {
        return Promise::rejectWith($reason);
    }

    /**
     * Suspend execution for the specified duration.
     *
     * @template T
     * @param float $milliseconds Duration to suspend in milliseconds
     * @param T $value The value to resolve with after the delay
     * @return PromiseInterface<T>
     */
    public static function delay(float $milliseconds, mixed $value = null): PromiseInterface
    {
        return EventLoop::getInstance()->delay($milliseconds, $value);
    }

    /**
     * Create a new Deferred for manual promise control.
     *
     * @param callable(): void|null $canceller Optional cancellation callback
     * @return Deferred<mixed>
     */
    public static function deferred(?callable $canceller = null): Deferred
    {
        return new Deferred($canceller);
    }

    /**
     * Returns a promise that resolves when all input promises resolve.
     *
     * @template T
     * @param iterable<PromiseInterface<T>|T> $promises The promises to wait for
     * @return PromiseInterface<array<T>>
     */
    public static function all(iterable $promises): PromiseInterface
    {
        // Convert iterable to array and ensure all values are promises
        $promiseArray = array_map(function (mixed $promise) {
            return $promise instanceof PromiseInterface ? $promise : self::resolve($promise);
        }, (array)$promises);

        if (empty($promiseArray)) {
            return self::resolve([]);
        }

        return new Promise(function (callable $resolve, callable $reject) use ($promiseArray): void {
            /** @var array<T> $results */
            $results = [];
            $remaining = count($promiseArray);

            foreach ($promiseArray as $key => $promise) {
                $promise->then(
                    function (mixed $value) use ($resolve, &$results, &$remaining, $key): void {
                        $results[$key] = $value;
                        $remaining--;

                        if ($remaining === 0) {
                            $resolve($results);
                        }
                    },
                    function (Throwable $reason) use ($reject): void {
                        $reject($reason);
                    }
                );
            }
        });
    }

    /**
     * Returns a promise that settles as soon as the first promise settles.
     *
     * @template T
     * @param iterable<PromiseInterface<T>|T> $promises The promises to race
     * @return PromiseInterface<T>
     */
    public static function race(iterable $promises): PromiseInterface
    {
        // Convert iterable to array and ensure all values are promises
        $promiseArray = array_map(function (mixed $promise) {
            return $promise instanceof PromiseInterface ? $promise : self::resolve($promise);
        }, (array)$promises);

        if (empty($promiseArray)) {
            throw new InvalidArgumentException('Race requires at least one promise');
        }

        return new Promise(function (callable $resolve, callable $reject) use ($promiseArray): void {
            $settled = false;

            foreach ($promiseArray as $promise) {
                $promise->then(
                    function (mixed $value) use ($resolve, &$settled): void {
                        if (!$settled) {
                            $settled = true;
                            $resolve($value);
                        }
                    },
                    function (Throwable $reason) use ($reject, &$settled): void {
                        if (!$settled) {
                            $settled = true;
                            $reject($reason);
                        }
                    }
                );
            }
        });
    }

    /**
     * Returns a promise that resolves as soon as any promise resolves.
     *
     * @template T
     * @param iterable<PromiseInterface<T>|T> $promises The promises to check
     * @return PromiseInterface<T>
     */
    public static function any(iterable $promises): PromiseInterface
    {
        // Convert iterable to array and ensure all values are promises
        $promiseArray = array_map(function (mixed $promise) {
            return $promise instanceof PromiseInterface ? $promise : self::resolve($promise);
        }, (array) $promises);

        if (empty($promiseArray)) {
            throw new InvalidArgumentException('Any requires at least one promise');
        }

        return new Promise(function (callable $resolve, callable $reject) use ($promiseArray): void {
            $errors = [];
            $remaining = count($promiseArray);
            $resolved = false;

            foreach ($promiseArray as $key => $promise) {
                $promise->then(
                    function (mixed $value) use ($resolve, &$resolved): void {
                        if (!$resolved) {
                            $resolved = true;
                            $resolve($value);
                        }
                    },
                    function (Throwable $reason) use ($reject, &$errors, &$remaining, $key): void {
                        $errors[$key] = $reason;
                        $remaining--;

                        if ($remaining === 0) {
                            $reject(new AggregateException('All promises rejected', $errors));
                        }
                    }
                );
            }
        });
    }

    /**
     * Returns a promise that resolves when all input promises settle.
     *
     * @template T
     * @param iterable<PromiseInterface<T>|T> $promises The promises to settle
     * @return PromiseInterface<array<array{status: PromiseState, value?: T, reason?: Throwable}>>
     */
    public static function allSettled(iterable $promises): PromiseInterface
    {
        // Convert iterable to array and ensure all values are promises
        $promiseArray = array_map(function (mixed $promise) {
            return $promise instanceof PromiseInterface ? $promise : self::resolve($promise);
        }, (array) $promises);

        if (empty($promiseArray)) {
            return self::resolve([]);
        }

        return new Promise(function (callable $resolve) use ($promiseArray): void {
            $results = [];
            $remaining = count($promiseArray);

            foreach ($promiseArray as $key => $promise) {
                $promise->then(
                    function (mixed $value) use (&$results, &$remaining, $key, $resolve): void {
                        $results[$key] = ['status' => PromiseState::FULFILLED, 'value' => $value];
                        $remaining--;

                        if ($remaining === 0) {
                            $resolve($results);
                        }
                    },
                    function (Throwable $reason) use (&$results, &$remaining, $key, $resolve): void {
                        $results[$key] = ['status' => PromiseState::REJECTED, 'reason' => $reason];
                        $remaining--;

                        if ($remaining === 0) {
                            $resolve($results);
                        }
                    }
                );
            }
        });
    }

    /**
     * Add a timeout to a promise operation.
     *
     * @template T
     * @param callable(): T $callable The callable to execute
     * @param float $milliseconds Timeout in milliseconds
     * @return PromiseInterface<T>
     */
    public static function timeout(callable $callable, float $milliseconds): PromiseInterface
    {
        if ($milliseconds <= 0) {
            throw new InvalidArgumentException("Timeout must be positive");
        }

        $cancellationToken = self::createTimeoutTokenSource($milliseconds);

        return self::run($callable, $cancellationToken->getToken());
    }

    /**
     * Execute multiple async tasks with a concurrency limit.
     *
     * @template T
     * @param iterable<callable(): PromiseInterface<T>> $tasks Array of functions that return promises
     * @param int $concurrency Maximum number of concurrent executions
     * @return PromiseInterface<array<T>>
     */
    public static function pool(iterable $tasks, int $concurrency = 10): PromiseInterface
    {
        if ($concurrency <= 0) {
            throw new InvalidArgumentException('Concurrency must be greater than 0');
        }

        $tasks = (array) $tasks;

        if (empty($tasks)) {
            return self::resolve([]);
        }

        return new Promise(function (callable $resolve, callable $reject) use (&$tasks, $concurrency): void {
            $results = [];
            $running = 0;
            $index = 0;
            $total = count($tasks);

            $executeNext = function () use (&$executeNext, &$running, &$index, &$results, &$tasks, $total, $concurrency, $resolve, $reject): void {
                if ($index >= $total && $running === 0) {
                    $resolve($results);
                    return;
                }

                while ($running < $concurrency && $index < $total) {
                    $currentIndex = $index++;
                    $running++;

                    try {
                        $task = $tasks[$currentIndex];
                        $task()->then(
                            function (mixed $value) use (&$running, &$results, $currentIndex, &$executeNext): void {
                                $results[$currentIndex] = $value;
                                $running--;
                                $executeNext();
                            },
                            function (Throwable $reason) use (&$running, $reject): void {
                                $running--;
                                $reject($reason);
                            }
                        );
                    } catch (Throwable $e) {
                        $running--;
                        $reject($e);
                        return;
                    }
                }
            };

            $executeNext();
        });
    }

    /**
     * Retry an async operation with exponential backoff.
     *
     * @template T
     * @param callable(): PromiseInterface<T> $operation The operation to retry
     * @param int $maxAttempts Maximum number of attempts
     * @param float $baseDelay Base delay in milliseconds (doubled on each retry)
     * @param float $maxDelay Maximum delay between retries
     * @return PromiseInterface<T>
     */
    public static function retry(
        callable $operation,
        int      $maxAttempts = 3,
        float    $baseDelay = 1000.0,
        float    $maxDelay = 30000.0
    ): PromiseInterface
    {
        if ($maxAttempts <= 0) {
            throw new InvalidArgumentException('Max attempts must be greater than 0');
        }

        $attempt = 1;

        /** @var Closure(): PromiseInterface<T> $tryOperation */
        $tryOperation = function () use ($operation, $maxAttempts, $baseDelay, $maxDelay, &$attempt, &$tryOperation): PromiseInterface {
            return self::run($operation)->catch(function (Throwable $error) use ($maxAttempts, $baseDelay, $maxDelay, &$attempt, &$tryOperation): PromiseInterface {
                if ($attempt >= $maxAttempts) {
                    throw $error;
                }

                $attempt++;
                $delayTime = min($baseDelay * pow(2, $attempt - 2), $maxDelay);

                return self::delay($delayTime)->then($tryOperation);
            });
        };

        return $tryOperation();
    }

    /**
     * Execute async operations in sequence (one after another).
     *
     * @template T
     * @param iterable<callable(): PromiseInterface<T>> $tasks Array of functions that return promises
     * @return PromiseInterface<array<T>>
     */
    public static function sequence(iterable $tasks): PromiseInterface
    {
        $tasks = (array) $tasks;

        if (empty($tasks)) {
            return self::resolve([]);
        }

        return new Promise(function (callable $resolve, callable $reject) use (&$tasks): void {
            $results = [];
            $index = 0;

            $executeNext = function () use (&$executeNext, &$results, &$index, &$tasks, $resolve, $reject): void {
                if ($index >= count($tasks)) {
                    $resolve($results);
                    return;
                }

                $currentIndex = $index++;

                try {
                    $task = $tasks[$currentIndex];
                    $task()->then(
                        function (mixed $value) use (&$results, $currentIndex, &$executeNext): void {
                            $results[$currentIndex] = $value;
                            $executeNext();
                        },
                        function (Throwable $reason) use ($reject): void {
                            $reject($reason);
                        }
                    );
                } catch (Throwable $e) {
                    $reject($e);
                }
            };

            $executeNext();
        });
    }

    /**
     * Create a debounced version of an async function.
     *
     * @template T
     * @param callable(): (T|PromiseInterface<T>) $operation The operation to debounce
     * @param float $delay Debounce delay in milliseconds
     * @return callable(): PromiseInterface<T>
     */
    public static function debounce(callable $operation, float $delay): callable
    {
        static $timers = [];
        static $counter = 0;

        $id = ++$counter;

        return function () use ($operation, $delay, $id, &$timers): PromiseInterface {
            $eventLoop = EventLoop::getInstance();

            // Cancel the previous timer if it exists
            if (isset($timers[$id])) {
                $eventLoop->clearTimer($timers[$id]);
            }

            $deferred = new Deferred();

            $timers[$id] = $eventLoop->setTimeout(function () use ($operation, $deferred, $id, &$timers): void {
                unset($timers[$id]);

                try {
                    $result = $operation();
                    if ($result instanceof PromiseInterface) {
                        $result->then(
                            fn($value) => $deferred->resolve($value),
                            fn($reason) => $deferred->reject($reason)
                        );
                    } else {
                        $deferred->resolve($result);
                    }
                } catch (Throwable $e) {
                    $deferred->reject($e);
                }
            }, $delay);

            return $deferred->promise();
        };
    }

    /**
     * Create a throttled version of an async function.
     *
     * This method rate-limits calls to the provided function to a maximum of one call per interval without discarding
     * the other calls. Further calls within the interval are delayed until the previous call completes. Delayed calls
     * are guaranteed to run in order and with the original arguments and context.
     *
     * @template T
     * @param callable(): (T|PromiseInterface<T>) $operation The operation to throttle
     * @param float $interval Minimum interval between executions in milliseconds
     * @return callable(): PromiseInterface<T>
     */
    public static function throttle(callable $operation, float $interval): callable
    {
        static $lastExecution = [];
        static $counter = 0;

        $id = ++$counter;
        $lastExecution[$id] = 0;

        return function () use ($operation, $interval, $id, &$lastExecution): PromiseInterface {
            $now = Async::getCurrentTime();
            $timeSinceLastExecution = $now - $lastExecution[$id];

            if ($lastExecution[$id] === 0 || $timeSinceLastExecution >= $interval) {
                $lastExecution[$id] = $now;
                return self::run($operation);
            } else {
                $waitTime = $interval - $timeSinceLastExecution;
                $lastExecution[$id] += $interval;
                return self::delay($waitTime)->then($operation);
            }
        };
    }

    /**
     * Create a cancellation token source.
     *
     * @return CancellationTokenSource A new cancellation token source
     */
    public static function createCancellationTokenSource(): CancellationTokenSource
    {
        return new CancellationTokenSource();
    }

    /**
     * Create a cancellation token source with a timeout.
     *
     * @param float $timeout The timeout in milliseconds
     * @return CancellationTokenSource A new source that will auto-cancel after the timeout
     */
    public static function createTimeoutTokenSource(float $timeout): CancellationTokenSource
    {
        return CancellationTokenSource::withTimeout($timeout);
    }

    /**
     * Create a never-cancel token source.
     *
     * @return CancellationTokenSource A source with a token that will never be canceled
     */
    public static function createNeverCancelTokenSource(): CancellationTokenSource
    {
        return CancellationTokenSource::never();
    }

    /**
     * Combine multiple cancellation tokens.
     *
     * @param CancellationTokenInterface ...$tokens The tokens to combine
     * @return CancellationTokenInterface A new token that represents the combination
     */
    public static function combineCancellationTokens(CancellationTokenInterface ...$tokens): CancellationTokenInterface
    {
        return CombinedCancellationToken::create(...$tokens);
    }

    /**
     * Schedule a task to be executed asynchronously.
     *
     * @param callable $task The task to execute
     * @return void
     */
    public static function schedule(callable $task): void
    {
        EventLoop::getInstance()->schedule($task);
    }

    /**
     * Schedule a task to be executed after a delay.
     *
     * @param callable $callback The callback to execute
     * @param float $delay Delay in milliseconds
     * @return int Timer ID that can be used to cancel the timer
     */
    public static function setTimeout(callable $callback, float $delay): int
    {
        return EventLoop::getInstance()->setTimeout($callback, $delay);
    }

    /**
     * Schedule a task to be executed repeatedly at intervals.
     *
     * @param callable $callback The callback to execute
     * @param float $interval Interval in milliseconds
     * @return int Timer ID that can be used to cancel the timer
     */
    public static function setInterval(callable $callback, float $interval): int
    {
        return EventLoop::getInstance()->setInterval($callback, $interval);
    }

    /**
     * Cancel a scheduled timer.
     *
     * @param int $timerId The timer's ID returned by setTimeout or setInterval
     * @return void
     */
    public static function clearTimer(int $timerId): void
    {
        EventLoop::getInstance()->clearTimer($timerId);
    }

    /**
     * Run the event loop until all pending work is completed.
     *
     * @return void
     * @throws Throwable
     */
    public static function startEventLoop(): void
    {
        EventLoop::getInstance()->start();
    }

    /**
     * Check if the event loop has pending work.
     *
     * @return bool True if there are pending tasks or timers
     */
    public static function hasPendingWork(): bool
    {
        return EventLoop::getInstance()->hasPendingWork();
    }

    /**
     * Resume the event loop and wait for pending work to complete.
     *
     * @return void
     *
     * @throws Throwable
     */
    public static function await(): void
    {
        EventLoop::getInstance()->resume();
    }

    /**
     * Yield control back to the event loop from within an asynchronous context.
     *
     * @return void
     *
     * @throws Throwable
     */
    public static function yield(): void
    {
        EventLoop::getInstance()->yield();
    }

    /**
     * Stop the event loop.
     *
     * @return void
     *
     * @throws Throwable
     */
    public static function stopEventLoop(): void
    {
        EventLoop::getInstance()->stop();
    }

    /**
     * Get the current time from the event loop.
     *
     * @return float Current time in milliseconds
     */
    public static function getCurrentTime(): float
    {
        return EventLoop::getInstance()->getCurrentTime();
    }

    /**
     * Check if the current PHP version supports Fibers.
     *
     * @return bool True if Fibers are supported
     */
    public static function supportsFibers(): bool
    {
        static $result = class_exists(Fiber::class);
        return $result;
    }

    /**
     * Check if PCNTL extension is available for signal handling.
     *
     * @return bool True if PCNTL is available
     */
    public static function supportsPCNTL(): bool
    {
        static $result = extension_loaded('pcntl');
        return $result;
    }
}
