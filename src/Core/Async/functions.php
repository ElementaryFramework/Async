<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use Throwable;

/**
 * Create a promise from a callback-based function.
 *
 * @template T
 * @param callable(): T $executor Function that receives resolve and reject callbacks
 * @return PromiseInterface<T>
 */
function promisify(callable $executor): PromiseInterface
{
    return new Promise(function (callable $resolve, callable $reject) use ($executor) {
        try {
            $resolve($executor());
        } catch (Throwable $e) {
            $reject($e);
        }
    });
}

/**
 * Execute a callable asynchronously using the event loop.
 *
 * @template T
 * @param callable(): T $callable The callable to execute
 * @param CancellationTokenInterface|null $cancellationToken Optional cancellation token
 * @return PromiseInterface<T>
 */
function async(callable $callable, ?CancellationTokenInterface $cancellationToken = null): PromiseInterface
{
    return Async::run($callable, $cancellationToken);
}

/**
 * Suspend execution for the specified duration.
 *
 * @param float $milliseconds Duration to suspend in milliseconds
 * @param mixed $value The value to resolve with after the delay
 * @return PromiseInterface<mixed>
 */
function delay(float $milliseconds, mixed $value = null): PromiseInterface
{
    return Async::delay($milliseconds, $value);
}

/**
 * Create a new Deferred for manual promise control.
 *
 * @param callable(): void|null $canceller Optional cancellation callback
 * @return Deferred<mixed>
 */
function deferred(?callable $canceller = null): Deferred
{
    return Async::deferred($canceller);
}

/**
 * Returns a promise that resolves when all input promises resolve.
 *
 * @template T
 * @param iterable<PromiseInterface<T>|T> $promises The promises to wait for
 * @return PromiseInterface<array<T>>
 */
function all(iterable $promises): PromiseInterface
{
    return Async::all($promises);
}

/**
 * Returns a promise that settles as soon as the first promise settles.
 *
 * @template T
 * @param iterable<PromiseInterface<T>|T> $promises The promises to race
 * @return PromiseInterface<T>
 */
function race(iterable $promises): PromiseInterface
{
    return Async::race($promises);
}

/**
 * Returns a promise that resolves as soon as any promise resolves.
 *
 * @template T
 * @param iterable<PromiseInterface<T>|T> $promises The promises to check
 * @return PromiseInterface<T>
 */
function any(iterable $promises): PromiseInterface
{
    return Async::any($promises);
}

/**
 * Returns a promise that resolves when all input promises settle.
 *
 * @template T
 * @param iterable<PromiseInterface<T>|T> $promises The promises to settle
 * @return PromiseInterface<array<array{status: PromiseState, value?: T, reason?: Throwable}>>
 */
function allSettled(iterable $promises): PromiseInterface
{
    return Async::allSettled($promises);
}

/**
 * Add a timeout to a promise operation.
 *
 * @template T
 * @param callable(): T $callable The promise to add timeout to
 * @param float $milliseconds Timeout in milliseconds
 * @return PromiseInterface<T>
 */
function timeout(callable $callable, float $milliseconds): PromiseInterface
{
    return Async::timeout($callable, $milliseconds);
}

/**
 * Execute multiple async tasks with a concurrency limit.
 *
 * @template T
 * @param iterable<callable(): PromiseInterface<T>> $tasks Array of functions that return promises
 * @param int $concurrency Maximum number of concurrent executions
 * @return PromiseInterface<array<T>>
 */
function pool(iterable $tasks, int $concurrency = 10): PromiseInterface
{
    return Async::pool($tasks, $concurrency);
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
function retry(
    callable $operation,
    int      $maxAttempts = 3,
    float    $baseDelay = 1000.0,
    float    $maxDelay = 30000.0
): PromiseInterface
{
    return Async::retry($operation, $maxAttempts, $baseDelay, $maxDelay);
}

/**
 * Execute async operations in sequence (one after another).
 *
 * @template T
 * @param iterable<callable(): PromiseInterface<T>> $tasks Array of functions that return promises
 * @return PromiseInterface<array<T>>
 */
function sequence(iterable $tasks): PromiseInterface
{
    return Async::sequence($tasks);
}

/**
 * Create a debounced version of an async function.
 *
 * @template T
 * @param callable(): PromiseInterface<T> $operation The operation to debounce
 * @param float $delay Debounce delay in milliseconds
 * @return callable(): PromiseInterface<T>
 */
function debounce(callable $operation, float $delay): callable
{
    return Async::debounce($operation, $delay);
}

/**
 * Create a throttled version of an async function.
 *
 * This method rate-limits calls to the provided function to a maximum of one call per interval without discarding
 * the other calls. Further calls within the interval are delayed until the previous call completes. Delayed calls
 * are guaranteed to run in order and with the original arguments and context.
 *
 * @template T
 * @param callable(): PromiseInterface<T> $operation The operation to throttle
 * @param float $interval Minimum interval between executions in milliseconds
 * @return callable(): PromiseInterface<T>
 */
function throttle(callable $operation, float $interval): callable
{
    return Async::throttle($operation, $interval);
}

/**
 * Schedule a task on the event loop.
 *
 * @param callable $task The task to schedule
 * @return void
 */
function schedule(callable $task): void
{
    Async::schedule($task);
}

/**
 * Set a timeout using the event loop.
 *
 * @param callable $callback The callback to execute
 * @param float $delay Delay in milliseconds
 * @return int Timer ID
 */
function setTimeout(callable $callback, float $delay): int
{
    return Async::setTimeout($callback, $delay);
}

/**
 * Set an interval using the event loop.
 *
 * @param callable $callback The callback to execute
 * @param float $interval Interval in milliseconds
 * @return int Timer ID
 */
function setInterval(callable $callback, float $interval): int
{
    return Async::setInterval($callback, $interval);
}

/**
 * Clear a timer using the event loop.
 *
 * @param int $timerId The timer ID to clear
 * @return void
 */
function clearTimer(int $timerId): void
{
    Async::clearTimer($timerId);
}

/**
 * Resume the event loop and wait for pending work to complete.
 *
 * @return void
 *
 * @throws Throwable
 */
function await(): void
{
    Async::await();
}
