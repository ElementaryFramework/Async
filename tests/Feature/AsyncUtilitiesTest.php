<?php

declare(strict_types=1);

use ElementaryFramework\Core\Async\AggregateException;
use ElementaryFramework\Core\Async\Async;
use ElementaryFramework\Core\Async\CancellationException;
use ElementaryFramework\Core\Async\Deferred;
use ElementaryFramework\Core\Async\PromiseInterface;
use ElementaryFramework\Core\Async\PromiseState;
use function ElementaryFramework\Core\Async\{all, allSettled, any, async, await, pool, race, retry, sequence, timeout};

// Import functions for testing

describe('Async Utilities', function () {
    describe('resolve function', function () {
        it('creates a resolved promise', function () {
            $promise = Async::resolve('test value');

            expect($promise)->toResolveWith('test value');
        });

        it('creates a resolved promise with null', function () {
            $promise = Async::resolve();

            expect($promise)->toResolveWith(null);
        });

        it('returns existing promise when resolving with promise', function () {
            $original = Async::resolve('value');
            $wrapped = Async::resolve($original);

            expect($wrapped)->toBe($original);
        });
    });

    describe('reject function', function () {
        it('creates a rejected promise', function () {
            $exception = new Exception('test error');
            $promise = Async::reject($exception);

            expect($promise)->toRejectWith(Exception::class, 'test error');
        });
    });

    describe('all function', function () {
        it('resolves with all values when all promises resolve', function () {
            $promises = [
                Async::resolve('first'),
                Async::resolve('second'),
                Async::resolve('third')
            ];

            $result = all($promises);

            expect($result)->toResolveWith(['first', 'second', 'third']);
        });

        it('resolves with empty array for empty input', function () {
            $result = all([]);

            expect($result)->toResolveWith([]);
        });

        it('rejects immediately when any promise rejects', function () {
            $exception = new Exception('test error');
            $promises = [
                Async::resolve('success'),
                Async::reject($exception),
                Async::resolve('another success')
            ];

            $result = all($promises);

            expect($result)->toRejectWith(Exception::class, 'test error');
        });

        it('handles mixed promises and values', function () {
            $promises = [
                Async::resolve('promise value'),
                'direct value',
                Async::resolve(42)
            ];

            $result = all($promises);

            expect($result)->toResolveWith(['promise value', 'direct value', 42]);
        });

        it('preserves original array keys', function () {
            $promises = [
                'key1' => Async::resolve('value1'),
                'key2' => Async::resolve('value2'),
                'key3' => Async::resolve('value3')
            ];

            $result = all($promises);

            expect($result)->toBeFulfilled();
            $values = $result->unwrap();
            expect($values)->toHaveKey('key1', 'value1')
                ->and($values)->toHaveKey('key2', 'value2')
                ->and($values)->toHaveKey('key3', 'value3');
        });
    });

    describe('race function', function () {
        it('resolves with first settled promise value', function () {
            $promises = [
                Async::resolve('fast'),
                Async::resolve('slow')
            ];

            $result = race($promises);

            expect($result)->toResolveWith('fast');
        });

        it('rejects with first settled promise rejection', function () {
            $exception = new Exception('first error');
            $promises = [
                Async::reject($exception),
                Async::resolve('success')
            ];

            $result = race($promises);

            expect($result)->toRejectWith(Exception::class, 'first error');
        });

        it('throws exception for empty input', function () {
            expect(fn() => race([]))
                ->toThrow(InvalidArgumentException::class, 'Race requires at least one promise');
        });

        it('handles mixed promises and values', function () {
            $promises = [
                'immediate value',
                Async::resolve('promise value')
            ];

            $result = race($promises);

            expect($result)->toResolveWith('immediate value');
        });
    });

    describe('any function', function () {
        it('resolves with first successful promise', function () {
            $promises = [
                Async::reject(new Exception('error 1')),
                Async::resolve('success'),
                Async::reject(new Exception('error 2'))
            ];

            $result = any($promises);

            expect($result)->toResolveWith('success');
        });

        it('rejects with AggregateException when all promises reject', function () {
            $promises = [
                Async::reject(new Exception('error 1')),
                Async::reject(new Exception('error 2')),
                Async::reject(new Exception('error 3'))
            ];

            $result = any($promises);

            expect($result)->toBeRejected();
            $reason = $result->getReason();
            expect($reason)->toBeInstanceOf(AggregateException::class)
                ->and($reason->getMessage())->toBe('All promises rejected')
                ->and($reason->getInnerExceptionCount())->toBe(3);
        });

        it('throws exception for empty input', function () {
            expect(fn() => any([]))
                ->toThrow(InvalidArgumentException::class, 'Any requires at least one promise');
        });

        it('resolves immediately with first value even if others are pending', function () {
            $deferred = new Deferred();
            $promises = [
                $deferred->promise(),
                Async::resolve('immediate success')
            ];

            $result = any($promises);

            expect($result)->toResolveWith('immediate success');
        });
    });

    describe('allSettled function', function () {
        it('resolves with all settlement results', function () {
            $promises = [
                Async::resolve('success 1'),
                Async::reject(new Exception('error 1')),
                Async::resolve('success 2')
            ];

            $result = allSettled($promises);
            await();

            expect($result)->toBeFulfilled();
            $settlements = $result->unwrap();

            expect($settlements)->toHaveCount(3)
                ->and($settlements[0])->toMatchArray(['status' => PromiseState::FULFILLED, 'value' => 'success 1'])
                ->and($settlements[1])->toMatchArray(['status' => PromiseState::REJECTED])
                ->and($settlements[1]['reason'])->toBeInstanceOf(Exception::class)
                ->and($settlements[2])->toMatchArray(['status' => PromiseState::FULFILLED, 'value' => 'success 2']);
        });

        it('resolves with empty array for empty input', function () {
            $result = allSettled([]);

            expect($result)->toResolveWith([]);
        });

        it('never rejects even when all promises reject', function () {
            $promises = [
                Async::reject(new Exception('error 1')),
                Async::reject(new Exception('error 2'))
            ];

            $result = allSettled($promises);
            await();

            expect($result)->toBeFulfilled();
            $settlements = $result->unwrap();

            expect($settlements)->toHaveCount(2)
                ->and($settlements[0]['status'])->toBe(PromiseState::REJECTED)
                ->and($settlements[1]['status'])->toBe(PromiseState::REJECTED);
        });
    });

    describe('timeout function', function () {
        it('returns original value when completes in time', function () {
            $original = fn() => 'value';

            $result = timeout($original, 1000);
            await();

            expect($result)->toBeFulfilled()
                ->and($result)->toResolveWith('value');
        });

        it('cancels execution of long running task', function () {
            $task = function () {
                for ($i = 0; $i < 100000000; $i++) {
                    usleep(1);
                    Async::yield();
                }
            };

            $result = timeout($task, 1000);
            await();

            expect($result)->toBeRejected()
                ->and($result->getReason())->toBeInstanceOf(CancellationException::class);
        });
    });

    describe('pool function', function () {
        it('executes tasks with limited concurrency', function () {
            $executionOrder = [];
            $tasks = [];

            for ($i = 0; $i < 5; $i++) {
                $tasks[] = function () use ($i, &$executionOrder) {
                    return async(function () use ($i, &$executionOrder) {
                        $executionOrder[] = $i;
                        return "Task $i";
                    });
                };
            }

            $result = pool($tasks, 2); // Concurrency limit of 2
            await();

            expect($result)->toBeFulfilled();
            $results = $result->unwrap();

            expect($results)->toHaveCount(5)
                ->and($results[0])->toBe('Task 0')
                ->and($results[4])->toBe('Task 4');
        });

        it('throws exception for invalid concurrency', function () {
            expect(fn() => pool([], 0))
                ->toThrow(InvalidArgumentException::class, 'Concurrency must be greater than 0')
                ->and(fn() => pool([], -1))
                ->toThrow(InvalidArgumentException::class, 'Concurrency must be greater than 0');
        });

        it('resolves with empty array for empty tasks', function () {
            $result = pool([]);

            expect($result)->toResolveWith([]);
        });

        it('rejects when any task fails', function () {
            $tasks = [
                fn() => Async::resolve('success 1'),
                fn() => Async::reject(new Exception('task failed')),
                fn() => Async::resolve('success 2')
            ];

            $result = pool($tasks, 10);

            expect($result)->toRejectWith(Exception::class, 'task failed');
        });
    });

    describe('retry function', function () {
        it('succeeds on first attempt when operation succeeds', function () {
            $attemptCount = 0;
            $operation = function () use (&$attemptCount) {
                $attemptCount++;
                return Async::resolve('success');
            };

            $result = retry($operation, 3);
            await();

            expect($result)->toResolveWith('success')
                ->and($attemptCount)->toBe(1);
        });

        it('retries on failure and eventually succeeds', function () {
            $attemptCount = 0;
            $operation = function () use (&$attemptCount) {
                $attemptCount++;
                return async(function () use ($attemptCount) {
                    if ($attemptCount < 3) {
                        throw new Exception("Attempt $attemptCount failed");
                    }
                    return "Success on attempt $attemptCount";
                });
            };

            $result = retry($operation, 5, 0.01, 1.0); // Short delays for testing
            await();

            expect($result)->toBeFulfilled()
                ->and($attemptCount)->toBe(3);
        });

        it('rejects after max attempts exceeded', function () {
            $attemptCount = 0;
            $operation = function () use (&$attemptCount) {
                $attemptCount++;
                return Async::reject(new Exception("Attempt $attemptCount failed"));
            };

            $result = retry($operation, 2, 0.01, 1.0);
            await();

            expect($result)->toRejectWith(Exception::class, 'Attempt 2 failed')
                ->and($attemptCount)->toBe(2);
        });

        it('throws exception for invalid max attempts', function () {
            expect(fn() => retry(fn() => Async::resolve('test'), 0))
                ->toThrow(InvalidArgumentException::class, 'Max attempts must be greater than 0');
        });
    });

    describe('sequence function', function () {
        it('executes tasks in order', function () {
            $executionOrder = [];
            $tasks = [];

            for ($i = 0; $i < 3; $i++) {
                $tasks[] = function () use ($i, &$executionOrder) {
                    return async(function () use ($i, &$executionOrder) {
                        $executionOrder[] = $i;
                        return "Task $i";
                    });
                };
            }

            $result = sequence($tasks);
            await();

            expect($result)->toBeFulfilled();
            $results = $result->unwrap();

            expect($results)->toHaveCount(3)
                ->and($results[0])->toBe('Task 0')
                ->and($results[1])->toBe('Task 1')
                ->and($results[2])->toBe('Task 2')
                ->and($executionOrder)->toBe([0, 1, 2]);
        });

        it('resolves with empty array for empty tasks', function () {
            $result = sequence([]);

            expect($result)->toResolveWith([]);
        });

        it('stops execution when a task fails', function () {
            $executionOrder = [];
            $tasks = [
                function () use (&$executionOrder) {
                    $executionOrder[] = 1;
                    return Async::resolve('Task 1');
                },
                function () use (&$executionOrder) {
                    $executionOrder[] = 2;
                    return Async::reject(new Exception('Task 2 failed'));
                },
                function () use (&$executionOrder) {
                    $executionOrder[] = 3;
                    return Async::resolve('Task 3');
                }
            ];

            $result = sequence($tasks);

            expect($result)->toRejectWith(Exception::class, 'Task 2 failed')
                ->and($executionOrder)->toBe([1, 2]); // Third task should not execute
        });
    });

    describe('async function', function () {
        it('executes callable asynchronously', function () {
            $executed = false;

            $promise = async(function () use (&$executed) {
                $executed = true;
                return 'async result';
            });
            await();

            expect($promise)->toBeFulfilled()
                ->and($executed)->toBeTrue();
        });

        it('handles exceptions in async operations', function () {
            $exception = new Exception('async error');
            $promise = async(function () use ($exception) {
                throw $exception;
            });
            await();

            expect($promise)->toBeRejected()
                ->and($promise->getReason())->toBe($exception);
        });

        it('respects cancellation tokens', function () {
            $tokenSource = Async::createCancellationTokenSource();
            $token = $tokenSource->getToken();

            $promise = async(function () use ($token) {
                $token->throwIfCancellationRequested();
                return 'completed';
            }, $token);

            expect($promise)->toBePromise();

            // Cancel the operation
            $tokenSource->cancel('test cancellation');
            await();

            expect($token->isCancellationRequested())->toBeTrue()
                ->and($promise)->toBeRejected();
        });
    });

    describe('integration scenarios', function () {
        it('can combine multiple utilities for complex workflows', function () {
            // Simulate a complex async workflow
            $workflow = function () {
                // Step 1: Fetch multiple data sources concurrently
                $dataPromises = [
                    async(fn() => 'user data'),
                    async(fn() => 'profile data'),
                    async(fn() => 'settings data')
                ];

                return all($dataPromises)
                    ->then(function ($results) {
                        // Step 2: Process the combined data
                        return async(function () use ($results) {
                            return [
                                'combined' => implode(', ', $results),
                                'processed_at' => time()
                            ];
                        });
                    });
            };

            /** @var PromiseInterface $result */
            $result = $workflow();
            await();

            expect($result)->toBeFulfilled()
                ->and($result->unwrap())->toBeArray()->toHaveKey('combined');
        });

        it('handles error scenarios gracefully', function () {
            // Test error recovery patterns
            $unreliableOperation = function () {
                return Async::reject(new Exception('Network error'));
            };

            $resilientOperation = function () use ($unreliableOperation) {
                return retry($unreliableOperation, 3, 0.01, 1.0)
                    ->catch(function ($error) {
                        // Fallback to cached data
                        return Async::resolve('cached data');
                    });
            };

            $result = $resilientOperation();
            await();

            expect($result)->toResolveWith('cached data');
        });

        it('supports timeout patterns', function () {
            $slowOperation = function () {
                for ($i = 0; $i < 100; ++$i) {
                    // Simulate slow operation
                    usleep(1000); // 1ms
                    Async::yield();
                }

                return 'slow result';
            };

            // Apply timeout
            $timedOperation = timeout($slowOperation, 50); // 50ms timeout
            await();

            expect($timedOperation)->toBePromise()->toBeRejected();
        });
    });
});
