<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

use BadMethodCallException;
use Fiber;
use Generator;
use SplObjectStorage;
use SplQueue;
use Throwable;

/**
 * Event loop for managing asynchronous operations using PHP Fibers.
 *
 * This class provides a simple event loop implementation that can:
 * - Schedule and execute asynchronous tasks using Fibers
 * - Handle timers and delayed execution
 * - Manage promise resolution and rejection
 * - Support cancellation through tokens
 * - Provide async/await-like functionality
 */
class EventLoop
{
    private static ?self $instance = null;

    /**
     * @var SplQueue<callable> Queue of tasks to be executed
     */
    private SplQueue $taskQueue;

    /**
     * @var array<int, array{callback: callable, time: float, interval: float|null, canceled: bool}> Scheduled timers
     */
    private array $timers = [];

    /**
     * @var int Next timer ID
     */
    private int $nextTimerId = 1;

    /**
     * @var bool Whether the event loop is running
     */
    private bool $running = false;

    /**
     * @var SplObjectStorage<Fiber<void, void, mixed, void>, mixed> Active fibers and their contexts
     */
    private SplObjectStorage $fibers;

    /**
     * @var float High-resolution time when the loop started
     */
    private float $startTime;

    /**
     * @var Fiber<void, void, void, void> Fiber that runs the event loop
     */
    private Fiber $loopFiber;

    /**
     * @var bool Whether the event loop has started
     */
    private bool $loopStarted = false;

    /**
     * Create a new EventLoop instance.
     */
    private function __construct()
    {
        $this->taskQueue = new SplQueue();
        $this->fibers = new SplObjectStorage();
        $this->startTime = $this->getCurrentTime();
        $this->loopFiber = new Fiber(function () {
            while ($this->loopStarted) {
                $this->run();
            }
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if (($error['type'] ?? 0) & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
                return;
            }

            $this->resume();
            $this->stop();
        });
    }

    /**
     * Get the singleton instance of the event loop.
     *
     * @return self The event loop instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Start the event loop.
     *
     * @return void
     *
     * @throws Throwable
     */
    public function start(): void
    {
        $this->loopStarted = true;
        $this->loopFiber->start();
    }

    /**
     * Resume the event loop.
     *
     * @return void
     *
     * @throws Throwable
     */
    public function resume(): void
    {
        if ($this->loopStarted && $this->loopFiber->isSuspended()) {
            $this->loopFiber->resume();
        }
    }

    /**
     * Stop the event loop.
     *
     * @return void
     *
     * @throws Throwable
     */
    public function stop(): void
    {
        $this->loopStarted = false;

        if ($this->loopFiber->isSuspended()) {
            $this->loopFiber->resume();
        }
    }

    /**
     * Run the event loop until all tasks are completed.
     *
     * @return void
     *
     * @throws Throwable
     */
    private function run(): void
    {
        if ($this->running) {
            return; // Prevent nested runs
        }

        $this->running = true;

        try {
            while ($this->hasPendingWork()) {
                $this->tick();

                // Small delay to prevent CPU spinning
                if (!$this->hasPendingWork()) {
                    usleep(1000); // 1ms
                }
            }
        } finally {
            $this->running = false;
            $this->yield();
        }
    }

    /**
     * Execute one iteration of the event loop.
     *
     * @return void
     */
    public function tick(): void
    {
        // Process timers
        $this->processTimers();

        // Process task queue
        $this->processTasks();

        // Process active fibers
        $this->processActiveFibers();
    }

    /**
     * Schedule a task to be executed asynchronously.
     *
     * @param callable $task The task to execute
     * @return void
     */
    public function schedule(callable $task): void
    {
        $this->taskQueue->enqueue($task);
    }

    /**
     * Schedule a task to be executed after a delay.
     *
     * @param callable $callback The callback to execute
     * @param float $delay Delay in milliseconds
     * @return int Timer ID that can be used to cancel the timer
     */
    public function setTimeout(callable $callback, float $delay): int
    {
        return $this->createTimer($callback, $delay, false);
    }

    /**
     * Schedule a task to be executed repeatedly at intervals.
     *
     * @param callable $callback The callback to execute
     * @param float $interval Interval in milliseconds
     * @return int Timer ID that can be used to cancel the timer
     */
    public function setInterval(callable $callback, float $interval): int
    {
        return $this->createTimer($callback, $interval, true);
    }

    /**
     * Cancel a scheduled timer.
     *
     * @param int $timerId The timer's ID returned by setTimeout or setInterval
     * @return void
     */
    public function clearTimer(int $timerId): void
    {
        if (isset($this->timers[$timerId])) {
            $this->timers[$timerId]['canceled'] = true;
        }
    }

    /**
     * Execute a callable asynchronously using a Fiber.
     *
     * @template T
     * @param callable(): T $callable The callable to execute
     * @param CancellationTokenInterface|null $cancellationToken Optional cancellation token
     * @return PromiseInterface<T>
     */
    public function async(callable $callable, ?CancellationTokenInterface $cancellationToken = null): PromiseInterface
    {
        $deferred = new Deferred();

        if ($cancellationToken?->isCancellationRequested()) {
            $deferred->reject(new CancellationException($cancellationToken->reason ?? 'Operation was cancelled'));
            return $deferred->promise();
        }

        $fiber = new Fiber(function () use ($callable, $deferred, $cancellationToken): void {
            try {
                // Check for cancellation before starting
                $cancellationToken?->throwIfCancellationRequested();

                $result = $callable();

                if ($result instanceof Generator) {
                    $value = null;
                    while ($result->valid()) {
                        $yielded = $result->current();

                        // If the yielded value is a Fiber, resume it
                        if ($yielded instanceof Fiber) {
                            $value = $yielded->isTerminated()
                                ? $yielded->getReturn()
                                : ($yielded->isSuspended() ? $yielded->resume() : $yielded->start());
                        } else {
                            $value = $yielded;
                        }

                        EventLoop::getInstance()->yield();
                        $result->next();
                    }

                    $deferred->resolve($result->getReturn() ?? $value ?? null);
                } else {
                    $deferred->resolve($result);
                }
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        // Store fiber context
        $this->fibers[$fiber] = [
            'deferred' => $deferred,
            'cancellationToken' => $cancellationToken,
            'startTime' => $this->getCurrentTime()
        ];

        // Set up cancellation if a token is provided
        $cancellationToken?->register(function () use ($fiber, $deferred): void {
            if (isset($this->fibers[$fiber])) {
                try {
                    if ($fiber->isRunning()) {
                        // Cannot directly terminate a running fiber, but we can mark it as canceled
                        $deferred->reject(new CancellationException('Fiber execution was canceled'));
                    } elseif ($fiber->isSuspended()) {
                        $fiber->throw(new CancellationException('Fiber execution was canceled'));
                    }
                } catch (Throwable $e) {
                    $deferred->reject($e);
                }
            }
        });

        return $deferred->promise();
    }

    /**
     * Suspend the current fiber for the specified duration.
     *
     * @template T
     * @param float $milliseconds Duration to suspend in milliseconds
     * @param T $value The value to resolve with after the delay
     * @return PromiseInterface<T>
     */
    public function delay(float $milliseconds, mixed $value = null): PromiseInterface
    {
        if ($milliseconds <= 0) {
            return Promise::resolveWith($value);
        }

        $deferred = new Deferred();

        $this->setTimeout(function () use ($deferred, $value): void {
            $deferred->resolve($value);
        }, $milliseconds);

        return $deferred->promise();
    }

    /**
     * Yield control back to the event loop from within a fiber.
     *
     * @return void
     * @throws Throwable
     *
     */
    public function yield(): void
    {
        if (Fiber::getCurrent() !== null) {
            Fiber::suspend();
        }
    }

    /**
     * Check if the event loop has pending work.
     *
     * @return bool True if there are pending tasks or timers
     */
    public function hasPendingWork(): bool
    {
        // Check for pending tasks
        if (!$this->taskQueue->isEmpty()) {
            return true;
        }

        // Check for active timers
        if (!empty($this->timers)) {
            return true;
        }

        // Check for active fibers
        foreach ($this->fibers as $fiber) {
            if ($fiber->isSuspended() || !$fiber->isTerminated()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the current high-resolution time.
     *
     * @return float Current time in milliseconds
     */
    public function getCurrentTime(): float
    {
        return hrtime(true) / 1e6;
    }

    /**
     * Get the time elapsed since the loop started.
     *
     * @return float Elapsed time in milliseconds
     */
    public function getElapsedTime(): float
    {
        return $this->getCurrentTime() - $this->startTime;
    }

    /**
     * Check if the event loop is currently running.
     *
     * @return bool True if the loop is running
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Reset the singleton instance (mainly for testing).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private function createTimer(callable $callback, float $delay, bool $repeat): int
    {
        $timerId = $this->nextTimerId++;
        $executeTime = $this->getCurrentTime() + $delay;

        $this->timers[$timerId] = [
            'callback' => $callback,
            'time' => $executeTime,
            'interval' => $repeat ? $delay : null,
            'canceled' => false
        ];

        return $timerId;
    }

    /**
     * Process scheduled timers.
     */
    private function processTimers(): void
    {
        $now = $this->getCurrentTime();

        foreach ($this->timers as $timerId => $timer) {
            if ($timer['canceled']) {
                unset($this->timers[$timerId]);
                continue;
            }

            if ($now >= $timer['time']) {
                // Execute the timer callback
                try {
                    ($timer['callback'])();
                } catch (Throwable $e) {
                    // Log timer callback errors
                    error_log("Timer callback error: " . $e->getMessage());
                }

                // Handle interval timers
                if ($timer['interval'] !== null) {
                    // Reschedule for next interval
                    $this->timers[$timerId]['time'] = $now + $timer['interval'];
                } else {
                    // One-time timer, remove it
                    unset($this->timers[$timerId]);
                }
            }
        }
    }

    /**
     * Process the task queue.
     *
     * @return void
     */
    private function processTasks(): void
    {
        $processedCount = 0;
        $maxTasks = 100; // Prevent infinite loops

        while (!$this->taskQueue->isEmpty() && $processedCount < $maxTasks) {
            $task = $this->taskQueue->dequeue();

            try {
                $task();
            } catch (Throwable $e) {
                // Log task execution errors
                error_log("Task execution error: " . $e->getMessage());
            }

            $processedCount++;
        }
    }

    /**
     * @return void
     */
    private function processActiveFibers(): void
    {
        foreach ($this->fibers as $fiber) {
            if ($fiber->isTerminated()) {
                $this->fibers->detach($fiber);
                continue;
            }

            try {
                if (!$fiber->isStarted()) {
                    $fiber->start();
                } elseif ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            } catch (Throwable $e) {
                // Fiber execution failed
                if (isset($this->fibers[$fiber])) {
                    $context = $this->fibers[$fiber];
                    if ($context['deferred'] instanceof Deferred && !$context['deferred']->settled) {
                        $context['deferred']->reject($e);
                    }
                }
            }
        }
    }
}
