<?php

declare(strict_types=1);

use ElementaryFramework\Core\Async\Deferred;
use ElementaryFramework\Core\Async\Promise;
use ElementaryFramework\Core\Async\PromiseInterface;
use ElementaryFramework\Core\Async\PromiseState;
use ElementaryFramework\Core\Async\CancellationException;

describe('Deferred', function () {
    it('can be created without arguments', function () {
        $deferred = new Deferred();

        expect($deferred)->toBeInstanceOf(Deferred::class)
            ->and($deferred->promise())->toBePromise()
            ->and($deferred->promise())->toBePending()
            ->and($deferred->settled)->toBeFalse();
    });

    it('can be created with a canceller', function () {
        $cancellerCalled = false;
        $deferred = new Deferred(function () use (&$cancellerCalled) {
            $cancellerCalled = true;
        });

        $deferred->cancel();

        expect($cancellerCalled)->toBeTrue()
            ->and($deferred->settled)->toBeTrue()
            ->and($deferred->promise())->toRejectWith(CancellationException::class);
    });

    describe('promise method', function () {
        it('returns a promise interface', function () {
            $deferred = new Deferred();
            $promise = $deferred->promise();

            expect($promise)->toBePromise()
                ->and($promise)->toBePending();
        });

        it('returns the same promise instance on multiple calls', function () {
            $deferred = new Deferred();
            $promise1 = $deferred->promise();
            $promise2 = $deferred->promise();

            expect($promise1)->toBe($promise2);
        });
    });

    describe('resolve method', function () {
        it('resolves the promise with a value', function () {
            $deferred = new Deferred();
            $deferred->resolve('test value');

            expect($deferred->promise())->toResolveWith('test value')
                ->and($deferred->settled)->toBeTrue()
                ->and($deferred->state)->toBe(PromiseState::FULFILLED);
        });

        it('resolves the promise with null by default', function () {
            $deferred = new Deferred();
            $deferred->resolve();

            expect($deferred->promise())->toResolveWith(null);
        });

        it('resolves with another promise', function () {
            $innerPromise = Promise::resolveWith('inner value');
            $deferred = new Deferred();
            $deferred->resolve($innerPromise);

            expect($deferred->promise())->toBeFulfilled()
                ->and($deferred->promise()->unwrap())->toBe('inner value');
        });

        it('throws when trying to resolve already settled deferred', function () {
            $deferred = new Deferred();
            $deferred->resolve('first');

            expect(fn() => $deferred->resolve('second'))
                ->toThrow(RuntimeException::class, 'Deferred is already settled');
        });

        it('throws when trying to resolve after rejection', function () {
            $deferred = new Deferred();
            $deferred->reject(new Exception('error'));

            expect(fn() => $deferred->resolve('value'))
                ->toThrow(RuntimeException::class, 'Deferred is already settled');
        });
    });

    describe('reject method', function () {
        it('rejects the promise with an exception', function () {
            $exception = new Exception('test error');
            $deferred = new Deferred();
            $deferred->reject($exception);

            expect($deferred->promise())->toRejectWith(Exception::class, 'test error')
                ->and($deferred->settled)->toBeTrue()
                ->and($deferred->state)->toBe(PromiseState::REJECTED);
        });

        it('throws when trying to reject already settled deferred', function () {
            $deferred = new Deferred();
            $deferred->resolve('value');

            expect(fn() => $deferred->reject(new Exception('error')))
                ->toThrow(RuntimeException::class, 'Deferred is already settled');
        });

        it('throws when trying to reject after rejection', function () {
            $deferred = new Deferred();
            $deferred->reject(new Exception('first'));

            expect(fn() => $deferred->reject(new Exception('second')))
                ->toThrow(RuntimeException::class, 'Deferred is already settled');
        });
    });

    describe('isSettled method', function () {
        it('returns false for new deferred', function () {
            $deferred = new Deferred();

            expect($deferred->settled)->toBeFalse();
        });

        it('returns true after resolution', function () {
            $deferred = new Deferred();
            $deferred->resolve('value');

            expect($deferred->settled)->toBeTrue();
        });

        it('returns true after rejection', function () {
            $deferred = new Deferred();
            $deferred->reject(new Exception('error'));

            expect($deferred->settled)->toBeTrue();
        });

        it('returns true after cancellation', function () {
            $deferred = new Deferred();
            $deferred->cancel();

            expect($deferred->settled)->toBeTrue();
        });
    });

    describe('getState method', function () {
        it('returns pending state for new deferred', function () {
            $deferred = new Deferred();

            expect($deferred->state)->toBe(PromiseState::PENDING);
        });

        it('returns fulfilled state after resolution', function () {
            $deferred = new Deferred();
            $deferred->resolve('value');

            expect($deferred->state)->toBe(PromiseState::FULFILLED);
        });

        it('returns rejected state after rejection', function () {
            $deferred = new Deferred();
            $deferred->reject(new Exception('error'));

            expect($deferred->state)->toBe(PromiseState::REJECTED);
        });
    });

    describe('cancel method', function () {
        it('cancels the promise when not settled', function () {
            $deferred = new Deferred();
            $deferred->cancel();

            expect($deferred->promise())->toRejectWith(CancellationException::class)
                ->and($deferred->settled)->toBeTrue();
        });

        it('does nothing when already settled by resolution', function () {
            $deferred = new Deferred();
            $deferred->resolve('value');
            $deferred->cancel();

            expect($deferred->promise())->toResolveWith('value');
        });

        it('does nothing when already settled by rejection', function () {
            $exception = new Exception('error');
            $deferred = new Deferred();
            $deferred->reject($exception);
            $deferred->cancel();

            expect($deferred->promise())->toRejectWith(Exception::class, 'error');
        });
    });

    describe('static factory methods', function () {
        describe('resolved', function () {
            it('creates a resolved deferred', function () {
                $deferred = Deferred::resolved('test value');

                expect($deferred->settled)->toBeTrue()
                    ->and($deferred->promise())->toResolveWith('test value');
            });

            it('creates a resolved deferred with null', function () {
                $deferred = Deferred::resolved();

                expect($deferred->promise())->toResolveWith(null);
            });
        });

        describe('rejected', function () {
            it('creates a rejected deferred', function () {
                $exception = new Exception('test error');
                $deferred = Deferred::rejected($exception);

                expect($deferred->settled)->toBeTrue()
                    ->and($deferred->promise())->toRejectWith(Exception::class, 'test error');
            });
        });

        describe('fromCallback', function () {
            it('creates deferred from successful callback', function () {
                $deferred = Deferred::fromCallback(function ($resolve, $reject) {
                    $resolve('success');
                });

                expect($deferred->promise())->toResolveWith('success');
            });

            it('creates deferred from failing callback', function () {
                $exception = new Exception('callback error');
                $deferred = Deferred::fromCallback(function ($resolve, $reject) use ($exception) {
                    $reject($exception);
                });

                expect($deferred->promise())->toRejectWith(Exception::class, 'callback error');
            });

            it('handles callback exceptions', function () {
                $exception = new Exception('thrown error');
                $deferred = Deferred::fromCallback(function () use ($exception) {
                    throw $exception;
                });

                expect($deferred->promise())->toRejectWith(Exception::class, 'thrown error');
            });

            it('works with Node.js style callback pattern', function () {
                $deferred = Deferred::fromCallback(function ($resolve, $reject) {
                    // Simulate Node.js callback with (error, result) pattern
                    $nodeCallback = function ($error, $result) use ($resolve, $reject) {
                        if ($error) {
                            $reject($error);
                        } else {
                            $resolve($result);
                        }
                    };

                    // Simulate async operation
                    $nodeCallback(null, 'async result');
                });

                expect($deferred->promise())->toResolveWith('async result');
            });
        });
    });

    describe('promise chaining with deferred', function () {
        it('allows chaining after resolution', function () {
            $deferred = new Deferred();
            $promise = $deferred->promise()
                ->then(fn($x) => $x * 2)
                ->then(fn($x) => "Result: $x");

            $deferred->resolve(5);

            expect($promise)->toResolveWith('Result: 10');
        });

        it('allows chaining after rejection', function () {
            $deferred = new Deferred();
            $promise = $deferred->promise()
                ->catch(fn($e) => 'Handled: ' . $e->getMessage())
                ->then(fn($x) => strtoupper($x));

            $deferred->reject(new Exception('error'));

            expect($promise)->toResolveWith('HANDLED: ERROR');
        });
    });

    describe('async integration', function () {
        it('can be used to convert callback-based APIs', function () {
            function simulateAsyncFileRead(string $filename, callable $callback): void {
                // Simulate async file read
                if ($filename === 'valid.txt') {
                    $callback(null, 'file contents');
                } else {
                    $callback(new Exception('File not found'), null);
                }
            }

            function promiseFileRead(string $filename): PromiseInterface {
                return Deferred::fromCallback(function ($resolve, $reject) use ($filename) {
                    simulateAsyncFileRead($filename, function ($error, $content) use ($resolve, $reject) {
                        if ($error) {
                            $reject($error);
                        } else {
                            $resolve($content);
                        }
                    });
                })->promise();
            }

            $successPromise = promiseFileRead('valid.txt');
            $errorPromise = promiseFileRead('invalid.txt');

            expect($successPromise)->toResolveWith('file contents')
                ->and($errorPromise)->toRejectWith(Exception::class, 'File not found');
        });

        it('can be used for manual promise control', function () {
            $deferred = [];

            $createDelayedPromise = function (float $delay, mixed $value) use (&$deferred): PromiseInterface {
                $d = new Deferred();
                $deferred[] = [$d, $value];
                return $d->promise();
            };

            $promises = [
                $createDelayedPromise(0.1, 'first'),
                $createDelayedPromise(0.2, 'second'),
                $createDelayedPromise(0.3, 'third')
            ];

            // Manually resolve all deferred
            foreach ($deferred as [$d, $v]) {
                $d->resolve($v);
            }

            expect($promises[0])->toResolveWith('first')
                ->and($promises[1])->toResolveWith('second')
                ->and($promises[2])->toResolveWith('third');
        });
    });

    describe('error handling', function () {
        it('maintains original promise instance type', function () {
            $deferred = new Deferred();
            $promise = $deferred->promise();

            expect($promise)->toBeInstanceOf(Promise::class);

            $deferred->resolve('value');

            expect($promise)->toBeInstanceOf(Promise::class)
                ->and($promise)->toBeFulfilled();
        });

        it('handles non-Promise instances gracefully', function () {
            // This test ensures our type checking works
            $deferred = new Deferred();

            // This should work fine since we create a Promise internally
            $deferred->resolve('test');

            expect($deferred->promise())->toResolveWith('test');
        });
    });

    describe('memory management', function () {
        it('can be garbage collected after settlement', function () {
            $deferred = new Deferred();
            $promise = $deferred->promise();

            $deferred->resolve('value');

            // Deferred should be cleanly settled
            expect($deferred->settled)->toBeTrue()
                ->and($promise)->toBeFulfilled();

            // Clear reference to deferred
            $deferred = null;

            // Promise should still work
            expect($promise)->toResolveWith('value');
        });
    });
});
