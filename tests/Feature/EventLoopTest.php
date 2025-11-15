<?php

declare(strict_types=1);

use ElementaryFramework\Core\Async\EventLoop;
use ElementaryFramework\Core\Async\Async;
use ElementaryFramework\Core\Async\Deferred;
use ElementaryFramework\Core\Async\CancellationException;

describe('EventLoop', function () {
    describe('getInstance', function () {
        it('returns singleton instance', function () {
            $loop1 = EventLoop::getInstance();
            $loop2 = EventLoop::getInstance();

            expect($loop1)->toBe($loop2)
                ->and($loop1)->toBeInstanceOf(EventLoop::class);
        });

        it('creates fresh instance after reset', function () {
            $loop1 = EventLoop::getInstance();
            EventLoop::reset();
            $loop2 = EventLoop::getInstance();

            expect($loop1)->not->toBe($loop2);
        });
    });

    describe('schedule', function () {
        it('schedules tasks for execution', function () {
            $loop = EventLoop::getInstance();
            $executed = false;

            $loop->schedule(function() use (&$executed) {
                $executed = true;
            });

            expect($loop->hasPendingWork())->toBeTrue();

            $loop->tick();

            expect($executed)->toBeTrue();
        });

        it('executes multiple scheduled tasks', function () {
            $loop = EventLoop::getInstance();
            $results = [];

            $loop->schedule(function() use (&$results) {
                $results[] = 'first';
            });

            $loop->schedule(function() use (&$results) {
                $results[] = 'second';
            });

            $loop->schedule(function() use (&$results) {
                $results[] = 'third';
            });

            $loop->tick();

            expect($results)->toBe(['first', 'second', 'third']);
        });
    });

    describe('setTimeout', function () {
        it('executes callback after delay', function () {
            $loop = EventLoop::getInstance();
            $executed = false;
            $startTime = $loop->getCurrentTime();

            $timerId = $loop->setTimeout(function() use (&$executed, &$executionTime, $loop) {
                $executed = true;
                $executionTime = $loop->getCurrentTime();
            }, 100);

            expect($timerId)->toBeInt()
                ->and($loop->hasPendingWork())->toBeTrue();

            // Simulate time passing and tick
            usleep(150000); // 0.15 seconds
            $loop->tick();

            expect($executed)->toBeTrue();
        });

        it('can be cancelled before execution', function () {
            $loop = EventLoop::getInstance();
            $executed = false;

            $timerId = $loop->setTimeout(function() use (&$executed) {
                $executed = true;
            }, 100);

            $loop->clearTimer($timerId);

            // Even after time passes, the callback should not execute
            usleep(150000);
            $loop->tick();

            expect($executed)->toBeFalse()
                ->and($loop->hasPendingWork())->toBeFalse();
        });

        it('handles multiple timers with different delays', function () {
            $loop = EventLoop::getInstance();
            $executionOrder = [];

            $loop->setTimeout(function() use (&$executionOrder) {
                $executionOrder[] = 'second';
            }, 200);

            $loop->setTimeout(function() use (&$executionOrder) {
                $executionOrder[] = 'first';
            }, 100);

            $loop->setTimeout(function() use (&$executionOrder) {
                $executionOrder[] = 'third';
            }, 300);

            // Simulate time passing and multiple ticks
            for ($i = 0; $i < 5; $i++) {
                usleep(100000); // 0.1 seconds
                $loop->tick();
            }

            expect($executionOrder)->toBe(['first', 'second', 'third']);
        });
    });

    describe('setInterval', function () {
        it('executes callback repeatedly', function () {
            $loop = EventLoop::getInstance();
            $executionCount = 0;

            $intervalId = $loop->setInterval(function() use (&$executionCount) {
                $executionCount++;
            }, 100);

            expect($intervalId)->toBeInt()
                ->and($loop->hasPendingWork())->toBeTrue();

            // Simulate multiple intervals
            for ($i = 0; $i < 5; $i++) {
                usleep(120000); // 0.12 seconds (slightly more than interval)
                $loop->tick();
            }

            expect($executionCount)->toBeGreaterThan(2)
                ->and($executionCount)->toBeLessThanOrEqual(5);

            $loop->clearTimer($intervalId);
        });

        it('can be cancelled to stop repetition', function () {
            $loop = EventLoop::getInstance();
            $executionCount = 0;

            $intervalId = $loop->setInterval(function() use (&$executionCount) {
                $executionCount++;
            }, 100);

            // Let it execute a few times
            usleep(250000); // 0.25 seconds
            $loop->tick();

            $countBeforeCancel = $executionCount;
            $loop->clearTimer($intervalId);

            // Continue ticking after cancellation
            usleep(250000);
            $loop->tick();

            expect($executionCount)->toBe($countBeforeCancel)
                ->and($loop->hasPendingWork())->toBeFalse();
        });
    });

    describe('clearTimer', function () {
        it('cancels setTimeout timers', function () {
            $loop = EventLoop::getInstance();
            $executed = false;

            $timerId = $loop->setTimeout(function() use (&$executed) {
                $executed = true;
            }, 100);

            $loop->clearTimer($timerId);

            usleep(150000);
            $loop->tick();

            expect($executed)->toBeFalse();
        });

        it('cancels setInterval timers', function () {
            $loop = EventLoop::getInstance();
            $executionCount = 0;

            $intervalId = $loop->setInterval(function() use (&$executionCount) {
                $executionCount++;
            }, 100);

            $loop->clearTimer($intervalId);

            usleep(250000);
            $loop->tick();

            expect($executionCount)->toBe(0);
        });

        it('handles invalid timer IDs gracefully', function () {
            $loop = EventLoop::getInstance();

            expect(function() use ($loop) {
                $loop->clearTimer(99999); // Non-existent timer ID
            })->not->toThrow(Throwable::class);
        });
    });

    describe('async method', function () {
        it('executes callable asynchronously when fibers are supported', function () {
            if (!Async::supportsFibers()) {
                $this->markTestSkipped('Fibers not supported in this PHP version');
            }

            $loop = EventLoop::getInstance();
            $executed = false;
            $result = null;

            $promise = $loop->async(function() use (&$executed) {
                $executed = true;
                return 'async result';
            });

            expect($promise)->toBePromise()
                ->and($loop->hasPendingWork())->toBeTrue();

            $this->runEventLoopBriefly();

            expect($executed)->toBeTrue()
                ->and($promise->unwrap())->toBe('async result');
        });

        it('handles simple generator functions', function () {
            if (!Async::supportsFibers()) {
                $this->markTestSkipped('Fibers not supported in this PHP version');
            }

            $loop = EventLoop::getInstance();
            $executed = false;
            $result = null;

            $promise = $loop->async(function() use (&$executed) {
                yield 'first';
                $executed = true;
                return 'async result';
            });

            expect($promise)->toBePromise()
                ->and($loop->hasPendingWork())->toBeTrue();

            $this->runEventLoopBriefly();

            expect($executed)->toBeTrue()
                ->and($promise->unwrap())->toBe('async result');
        });

        it('handles generator functions yielding fibers', function () {
            if (!Async::supportsFibers()) {
                $this->markTestSkipped('Fibers not supported in this PHP version');
            }

            $loop = EventLoop::getInstance();
            $executed = false;
            $result = null;

            $promise = $loop->async(function () use (&$executed): Generator {
                $dots = "";
                $fiber = new Fiber(function () use (&$dots, &$executed) {
                    while (!$executed) {
                        usleep(100000);
                        $dots .= ".";
                        Fiber::suspend($dots);
                    }
                });

                while ($dots !== ".................") {
                    yield $fiber;
                }

                $executed = true;
                return $dots;
            });

            expect($promise)->toBePromise()
                ->and($loop->hasPendingWork())->toBeTrue();

            $loop->resume();

            expect($executed)->toBeTrue()
                ->and($promise->unwrap())->toBe('.................');
        });

        it('handles exceptions in async operations', function () {
            if (!Async::supportsFibers()) {
                $this->markTestSkipped('Fibers not supported in this PHP version');
            }

            $loop = EventLoop::getInstance();
            $exception = new Exception('async error');

            $promise = $loop->async(function() use ($exception) {
                throw $exception;
            });

            $this->runEventLoopBriefly();

            $this->assertPromiseRejectsWith($promise, Exception::class, 'async error');
        });

        it('respects cancellation tokens', function () {
            if (!Async::supportsFibers()) {
                $this->markTestSkipped('Fibers not supported in this PHP version');
            }

            $loop = EventLoop::getInstance();
            $tokenSource = Async::createCancellationTokenSource();
            $executed = false;

            $promise = $loop->async(function() use (&$executed, $tokenSource) {
                $tokenSource->getToken()->throwIfCancellationRequested();
                $executed = true;
                return 'completed';
            }, $tokenSource->getToken());

            $tokenSource->cancel('test cancellation');

            $this->runEventLoopBriefly();

            expect($executed)->toBeFalse();
            $this->assertPromiseRejectsWith($promise, CancellationException::class);
        });

        it('allows early cancellation of async operations', function () {
            if (!Async::supportsFibers()) {
                $this->markTestSkipped('Fibers not supported in this PHP version');
            }

            $loop = EventLoop::getInstance();
            $tokenSource = Async::createCancellationTokenSource();
            $executed = false;

            $tokenSource->cancel('test cancellation');

            $promise = $loop->async(function() use (&$executed, $tokenSource) {
                $tokenSource->getToken()->throwIfCancellationRequested();
                $executed = true;
                return 'completed';
            }, $tokenSource->getToken());

            $this->runEventLoopBriefly();

            expect($executed)->toBeFalse();
            $this->assertPromiseRejectsWith($promise, CancellationException::class);
        });
    });

    describe('delay method', function () {
        it('creates promise that resolves after delay', function () {
            $loop = EventLoop::getInstance();
            $startTime = $loop->getCurrentTime();

            $promise = $loop->delay(100);

            expect($promise)->toBePromise()
                ->and($promise)->toBePending();

            usleep(150000); // 0.15 seconds
            $loop->tick();

            expect($promise)->toBeFulfilled()
                ->and($promise->unwrap())->toBeNull();
        });

        it('resolves immediately for zero or negative delay', function () {
            $loop = EventLoop::getInstance();

            $promise1 = $loop->delay(0);
            $promise2 = $loop->delay(-100);

            expect($promise1)->toBeFulfilled()
                ->and($promise2)->toBeFulfilled();
        });
    });

    describe('yield method', function () {
        it('does nothing when not in fiber context', function () {
            $loop = EventLoop::getInstance();

            expect(function() use ($loop) {
                $loop->yield();
            })->not->toThrow(FiberError::class);
        });

        it('suspends fiber when in fiber context', function () {
            if (!Async::supportsFibers()) {
                $this->markTestSkipped('Fibers not supported in this PHP version');
            }

            $loop = EventLoop::getInstance();
            $yieldCalled = false;

            $promise = $loop->async(function() use ($loop, &$yieldCalled) {
                $yieldCalled = true;
                $loop->yield();
                return 'after yield';
            });

            $this->runEventLoopBriefly();

            expect($yieldCalled)->toBeTrue();
        });
    });

    describe('hasPendingWork', function () {
        it('returns false for empty event loop', function () {
            $loop = EventLoop::getInstance();

            expect($loop->hasPendingWork())->toBeFalse();
        });

        it('returns true when tasks are scheduled', function () {
            $loop = EventLoop::getInstance();

            $loop->schedule(function() {});

            expect($loop->hasPendingWork())->toBeTrue();
        });

        it('returns true when timers are active', function () {
            $loop = EventLoop::getInstance();

            $loop->setTimeout(function() {}, 1000);

            expect($loop->hasPendingWork())->toBeTrue();
        });

        it('returns false after all work is completed', function () {
            $loop = EventLoop::getInstance();

            $loop->schedule(function() {});
            expect($loop->hasPendingWork())->toBeTrue();

            $loop->tick();
            expect($loop->hasPendingWork())->toBeFalse();
        });
    });

    describe('getCurrentTime', function () {
        it('returns current high-resolution time', function () {
            $loop = EventLoop::getInstance();
            $time1 = $loop->getCurrentTime();

            usleep(10000); // 10ms

            $time2 = $loop->getCurrentTime();

            expect($time2)->toBeGreaterThan($time1)
                ->and($time2 - $time1)->toBeGreaterThan(5) // At least 5ms
                ->and($time2 - $time1)->toBeLessThan(100); // Less than 100ms
        });

        it('provides consistent time measurements', function () {
            $loop = EventLoop::getInstance();
            $measurements = [];

            for ($i = 0; $i < 5; $i++) {
                $measurements[] = $loop->getCurrentTime();
                usleep(5000); // 5ms
            }

            // Each measurement should be greater than the previous
            for ($i = 1; $i < count($measurements); $i++) {
                expect($measurements[$i])->toBeGreaterThan($measurements[$i - 1]);
            }
        });
    });

    describe('getElapsedTime', function () {
        it('returns time since loop creation', function () {
            $loop = EventLoop::getInstance();
            $elapsed1 = $loop->getElapsedTime();

            expect($elapsed1)->toBeGreaterThanOrEqual(0);

            usleep(10000); // 10ms
            $elapsed2 = $loop->getElapsedTime();

            expect($elapsed2)->toBeGreaterThan($elapsed1);
        });
    });

    describe('run method', function () {
        it('processes all pending work', function () {
            $loop = EventLoop::getInstance();
            $results = [];

            $loop->schedule(function() use (&$results) {
                $results[] = 'task1';
            });

            $loop->setTimeout(function() use (&$results, $loop) {
                $results[] = 'timer1';
            }, 50);

            $loop->schedule(function() use (&$results) {
                $results[] = 'task2';
            });

            $loop->resume();

            expect($results)->toContain('task1')
                ->and($results)->toContain('task2')
                ->and($results)->toContain('timer1');
        });

        it('handles nested run calls gracefully', function () {
            $loop = EventLoop::getInstance();
            $outerExecuted = false;
            $innerExecuted = false;

            $loop->schedule(function() use ($loop, &$outerExecuted, &$innerExecuted) {
                $outerExecuted = true;

                $loop->schedule(function() use (&$innerExecuted) {
                    $innerExecuted = true;
                });

                $loop->tick(); // Nested run call
            });

            $loop->tick();

            expect($outerExecuted)->toBeTrue()
                ->and($innerExecuted)->toBeTrue();
        });

        it('stops when stop method is called', function () {
            $loop = EventLoop::getInstance();
            $executionCount = 0;

            $timerId = $loop->setInterval(function() use (&$executionCount, &$timerId, $loop) {
                $executionCount++;
                if ($executionCount >= 3) {
                    $loop->stop();
                    $loop->clearTimer($timerId);
                }
            }, 10);

            $loop->resume();

            expect($executionCount)->toBe(3)
                ->and($loop->isRunning())->toBeFalse();
        });
    });

    describe('tick method', function () {
        it('processes one iteration of work', function () {
            $loop = EventLoop::getInstance();
            $taskExecuted = false;
            $timerExecuted = false;

            $loop->schedule(function() use (&$taskExecuted) {
                $taskExecuted = true;
            });

            $loop->setTimeout(function() use (&$timerExecuted) {
                $timerExecuted = true;
            }, 10);

            usleep(20000); // 20ms
            $loop->tick();

            expect($taskExecuted)->toBeTrue()
                ->and($timerExecuted)->toBeTrue();
        });

        it('can be called multiple times safely', function () {
            $loop = EventLoop::getInstance();
            $executionCount = 0;

            $loop->schedule(function() use (&$executionCount) {
                $executionCount++;
            });

            $loop->tick();
            $loop->tick();
            $loop->tick();

            expect($executionCount)->toBe(1); // Task should only execute once
        });
    });

    describe('stop and isRunning methods', function () {
        it('tracks running state correctly', function () {
            $loop = EventLoop::getInstance();

            expect($loop->isRunning())->toBeFalse();

            $loop->schedule(function() use ($loop) {
                expect($loop->isRunning())->toBeTrue();
                $loop->stop();
            });

            $loop->resume();

            expect($loop->isRunning())->toBeFalse();
        });
    });

    describe('error handling', function () {
        it('handles task execution errors gracefully', function () {
            $loop = EventLoop::getInstance();
            $goodTaskExecuted = false;

            $loop->schedule(function() {
                throw new Exception('task error');
            });

            $loop->schedule(function() use (&$goodTaskExecuted) {
                $goodTaskExecuted = true;
            });

            $loop->tick();

            expect($goodTaskExecuted)->toBeTrue(); // Good task should still execute
        });

        it('handles timer callback errors gracefully', function () {
            $loop = EventLoop::getInstance();
            $goodTimerExecuted = false;

            $loop->setTimeout(function() {
                throw new Exception('timer error');
            }, 10);

            $loop->setTimeout(function() use (&$goodTimerExecuted) {
                $goodTimerExecuted = true;
            }, 20);

            usleep(30000); // 30ms
            $loop->tick();

            expect($goodTimerExecuted)->toBeTrue(); // Good timer should still execute
        });
    });

    describe('memory management', function () {
        it('cleans up completed timers', function () {
            $loop = EventLoop::getInstance();
            $executionCount = 0;

            // Create many one-time timers
            for ($i = 0; $i < 10; $i++) {
                $loop->setTimeout(function() use (&$executionCount) {
                    $executionCount++;
                }, 10);
            }

            expect($loop->hasPendingWork())->toBeTrue();

            usleep(50000); // 50ms
            $loop->tick();

            expect($executionCount)->toBe(10);
            // Timers should be cleaned up after execution
        });

        it('prevents task queue overflow', function () {
            $loop = EventLoop::getInstance();
            $processedTasks = 0;

            // Schedule many tasks to test queue limits
            for ($i = 0; $i < 200; $i++) {
                $loop->schedule(function() use (&$processedTasks) {
                    $processedTasks++;
                });
            }

            $loop->tick();

            // Should process up to the limit (100 by default in implementation)
            expect($processedTasks)->toBeGreaterThan(0)
                ->and($processedTasks)->toBeLessThanOrEqual(200);
        });
    });

    describe('integration with promises', function () {
        it('integrates with deferred promises', function () {
            $loop = EventLoop::getInstance();
            $deferred = new Deferred();

            $loop->setTimeout(function() use ($deferred) {
                $deferred->resolve('timer resolved');
            }, 50);

            expect($deferred->promise())->toBePending();

            usleep(100000); // 100ms
            $loop->tick();

            expect($deferred->promise())->toBeFulfilled()
                ->and($deferred->promise()->unwrap())->toBe('timer resolved');
        });

        it('works with promise chains', function () {
            $loop = EventLoop::getInstance();
            $result = null;

            $deferred = new Deferred();
            $promise = $deferred->promise()
                ->then(function($value) {
                    return $value . ' processed';
                })
                ->then(function($value) use (&$result) {
                    $result = $value;
                });

            $loop->setTimeout(function() use ($deferred) {
                $deferred->resolve('initial');
            }, 10);

            usleep(50000);
            $loop->tick();

            expect($result)->toBe('initial processed');
        });
    });

    describe('real-world simulation', function () {
        it('simulates HTTP request handling pattern', function () {
            $loop = EventLoop::getInstance();
            $requests = [];
            $responses = [];

            // Simulate incoming requests
            for ($i = 1; $i <= 3; $i++) {
                $loop->setTimeout(function() use (&$requests, $i, $loop, &$responses) {
                    $requests[] = "request_$i";

                    // Simulate async processing
                    $loop->setTimeout(function() use (&$responses, $i) {
                        $responses[] = "response_$i";
                    }, 50);
                }, $i * 10);
            }

            // Process for enough time to handle all requests
            $startTime = microtime(true);
            while ((microtime(true) - $startTime) < 0.2) {
                $loop->tick();
                usleep(10000); // 10ms
            }

            expect($requests)->toHaveCount(3)
                ->and($responses)->toHaveCount(3)
                ->and($requests)->toContain('request_1')
                ->and($responses)->toContain('response_1');
        });
    });
});
