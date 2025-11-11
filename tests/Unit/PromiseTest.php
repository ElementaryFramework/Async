<?php

declare(strict_types=1);

use ElementaryFramework\Core\Async\Promise;
use ElementaryFramework\Core\Async\PromiseState;
use ElementaryFramework\Core\Async\CancellationException;

describe('Promise', function () {
    it('can be created with a resolver function', function () {
        $promise = new Promise(function ($resolve, $reject) {
            $resolve('test value');
        });

        expect($promise)->toBePromise()
            ->and($promise->state)->toBe(PromiseState::FULFILLED)
            ->and($promise->unwrap())->toBe('test value');
    });

    it('can be created without an executor', function () {
        $promise = new Promise();

        expect($promise)->toBePromise()
            ->and($promise->state)->toBe(PromiseState::PENDING)
            ->and($promise->isPending())->toBeTrue();
    });

    it('rejects when executor throws an exception', function () {
        $exception = new Exception('test error');

        $promise = new Promise(function () use ($exception) {
            throw $exception;
        });

        expect($promise)->toBeRejected()
            ->and($promise->getReason())->toBe($exception);
    });

    it('can resolve with a value', function () {
        $promise = new Promise(function ($resolve) {
            $resolve(42);
        });

        expect($promise)->toResolveWith(42);
    });

    it('can resolve with another promise', function () {
        $innerPromise = Promise::resolveWith('inner value');

        $promise = new Promise(function ($resolve) use ($innerPromise) {
            $resolve($innerPromise);
        });

        expect($promise)->toBeFulfilled()
            ->and($promise->unwrap())->toBe('inner value');
    });

    it('can reject with an exception', function () {
        $exception = new RuntimeException('test rejection');

        $promise = new Promise(function ($resolve, $reject) use ($exception) {
            $reject($exception);
        });

        expect($promise)->toRejectWith(RuntimeException::class, 'test rejection');
    });

    it('cannot be resolved twice', function () {
        $promise = new Promise(function ($resolve) {
            $resolve('first');
            $resolve('second'); // This should be ignored
        });

        expect($promise)->toResolveWith('first');
    });

    it('cannot be rejected after resolution', function () {
        $promise = new Promise(function ($resolve, $reject) {
            $resolve('value');
            $reject(new Exception('error')); // This should be ignored
        });

        expect($promise)->toResolveWith('value');
    });

    describe('then method', function () {
        it('calls fulfillment handler when resolved', function () {
            $promise = Promise::resolveWith(10);
            $called = false;
            $receivedValue = null;

            $newPromise = $promise->then(function ($value) use (&$called, &$receivedValue) {
                $called = true;
                $receivedValue = $value;
                return $value * 2;
            });

            expect($called)->toBeTrue()
                ->and($receivedValue)->toBe(10)
                ->and($newPromise)->toResolveWith(20);
        });

        it('calls rejection handler when rejected', function () {
            $exception = new Exception('test error');
            $promise = Promise::rejectWith($exception);
            $called = false;
            $receivedError = null;

            $newPromise = $promise->then(null, function ($error) use (&$called, &$receivedError) {
                $called = true;
                $receivedError = $error;
                return 'handled';
            });

            expect($called)->toBeTrue()
                ->and($receivedError)->toBe($exception)
                ->and($newPromise)->toResolveWith('handled');
        });

        it('chains promises correctly', function () {
            $promise = Promise::resolveWith(5)
                ->then(fn($x) => $x * 2)
                ->then(fn($x) => $x + 1)
                ->then(fn($x) => "Result: $x");

            expect($promise)->toResolveWith('Result: 11');
        });

        it('handles thrown exceptions in fulfillment handler', function () {
            $exception = new RuntimeException('handler error');
            $promise = Promise::resolveWith('value')
                ->then(function () use ($exception) {
                    throw $exception;
                });

            expect($promise)->toRejectWith(RuntimeException::class, 'handler error');
        });

        it('handles thrown exceptions in rejection handler', function () {
            $originalError = new Exception('original');
            $handlerError = new RuntimeException('handler error');

            $promise = Promise::rejectWith($originalError)
                ->catch(function () use ($handlerError) {
                    throw $handlerError;
                });

            expect($promise)->toRejectWith(RuntimeException::class, 'handler error');
        });

        it('passes through values when no handler provided', function () {
            $promise = Promise::resolveWith('value')->then();

            expect($promise)->toResolveWith('value');
        });

        it('passes through rejections when no handler provided', function () {
            $exception = new Exception('error');
            $promise = Promise::rejectWith($exception)->then();

            expect($promise)->toRejectWith(Exception::class, 'error');
        });
    });

    describe('catch method', function () {
        it('handles rejections', function () {
            $exception = new Exception('test error');
            $promise = Promise::rejectWith($exception)
                ->catch(fn($error) => 'caught: ' . $error->getMessage());

            expect($promise)->toResolveWith('caught: test error');
        });

        it('is ignored when promise is fulfilled', function () {
            $promise = Promise::resolveWith('success')
                ->catch(fn() => 'this should not be called');

            expect($promise)->toResolveWith('success');
        });

        it('can filter by exception type', function () {
            $runtime = new RuntimeException('runtime error');
            $invalid = new InvalidArgumentException('invalid arg');

            $promise1 = Promise::rejectWith($runtime)
                ->catch(function (RuntimeException $e) {
                    return 'caught runtime: ' . $e->getMessage();
                });

            $promise2 = Promise::rejectWith($invalid)
                ->catch(function (RuntimeException $e) {
                    return 'caught runtime: ' . $e->getMessage();
                })
                ->catch(function (InvalidArgumentException $e) {
                    return 'caught invalid: ' . $e->getMessage();
                });

            expect($promise1)->toResolveWith('caught runtime: runtime error')
                ->and($promise2)->toResolveWith('caught invalid: invalid arg');
        });
    });

    describe('finally method', function () {
        it('executes callback on fulfillment', function () {
            $called = false;
            $promise = Promise::resolveWith('value')
                ->finally(function () use (&$called) {
                    $called = true;
                });

            expect($called)->toBeTrue()
                ->and($promise)->toResolveWith('value');
        });

        it('executes callback on rejection', function () {
            $called = false;
            $exception = new Exception('error');
            $promise = Promise::rejectWith($exception)
                ->finally(function () use (&$called) {
                    $called = true;
                });

            expect($called)->toBeTrue()
                ->and($promise)->toRejectWith(Exception::class, 'error');
        });

        it('preserves original value when callback returns value', function () {
            $promise = Promise::resolveWith('original')
                ->finally(fn() => 'different value');

            expect($promise)->toResolveWith('original');
        });

        it('preserves original rejection when callback returns value', function () {
            $exception = new Exception('original error');
            $promise = Promise::rejectWith($exception)
                ->finally(fn() => 'some value');

            expect($promise)->toRejectWith(Exception::class, 'original error');
        });

        it('rejects when callback throws exception', function () {
            $finallyError = new RuntimeException('finally error');
            $promise = Promise::resolveWith('value')
                ->finally(function () use ($finallyError) {
                    throw $finallyError;
                });

            expect($promise)->toRejectWith(RuntimeException::class, 'finally error');
        });
    });

    describe('cancel method', function () {
        it('cancels a pending promise', function () {
            $promise = new Promise();
            $promise->cancel();

            expect($promise)->toRejectWith(CancellationException::class);
        });

        it('does not affect settled promises', function () {
            $promise = Promise::resolveWith('value');
            $promise->cancel();

            expect($promise)->toResolveWith('value');
        });

        it('calls canceller function when provided', function () {
            $cancellerCalled = false;
            $promise = new Promise(null, function () use (&$cancellerCalled) {
                $cancellerCalled = true;
            });

            $promise->cancel();

            expect($cancellerCalled)->toBeTrue()
                ->and($promise)->toRejectWith(CancellationException::class);
        });

        it('handles canceller exceptions', function () {
            $cancellerError = new RuntimeException('canceller error');
            $promise = new Promise(null, function () use ($cancellerError) {
                throw $cancellerError;
            });

            $promise->cancel();

            expect($promise)->toRejectWith(RuntimeException::class, 'canceller error');
        });
    });

    describe('state methods', function () {
        it('reports correct state for pending promise', function () {
            $promise = new Promise();

            expect($promise->state)->toBe(PromiseState::PENDING)
                ->and($promise->isPending())->toBeTrue()
                ->and($promise->isFulfilled())->toBeFalse()
                ->and($promise->isRejected())->toBeFalse()
                ->and($promise->isSettled())->toBeFalse();
        });

        it('reports correct state for fulfilled promise', function () {
            $promise = Promise::resolveWith('value');

            expect($promise->state)->toBe(PromiseState::FULFILLED)
                ->and($promise->isPending())->toBeFalse()
                ->and($promise->isFulfilled())->toBeTrue()
                ->and($promise->isRejected())->toBeFalse()
                ->and($promise->isSettled())->toBeTrue();
        });

        it('reports correct state for rejected promise', function () {
            $promise = Promise::rejectWith(new Exception('error'));

            expect($promise->state)->toBe(PromiseState::REJECTED)
                ->and($promise->isPending())->toBeFalse()
                ->and($promise->isFulfilled())->toBeFalse()
                ->and($promise->isRejected())->toBeTrue()
                ->and($promise->isSettled())->toBeTrue();
        });
    });

    describe('getValue method', function () {
        it('returns value for fulfilled promise', function () {
            $promise = Promise::resolveWith('test value');

            expect($promise->unwrap())->toBe('test value');
        });

        it('throws exception for non-fulfilled promise', function () {
            $promise = new Promise();

            expect(fn() => $promise->unwrap())
                ->toThrow(RuntimeException::class, 'Promise is not fulfilled');
        });
    });

    describe('getReason method', function () {
        it('returns reason for rejected promise', function () {
            $exception = new Exception('test error');
            $promise = Promise::rejectWith($exception);

            expect($promise->getReason())->toBe($exception);
        });

        it('throws exception for non-rejected promise', function () {
            $promise = Promise::resolveWith('value');

            expect(fn() => $promise->getReason())
                ->toThrow(RuntimeException::class, 'Promise is not rejected');
        });
    });

    describe('static factory methods', function () {
        it('creates resolved promise with resolveWith', function () {
            $promise = Promise::resolveWith('test value');

            expect($promise)->toResolveWith('test value');
        });

        it('creates resolved promise with null value', function () {
            $promise = Promise::resolveWith();

            expect($promise)->toResolveWith(null);
        });

        it('returns existing promise when resolving with promise', function () {
            $original = Promise::resolveWith('value');
            $wrapped = Promise::resolveWith($original);

            expect($wrapped)->toBe($original);
        });

        it('creates rejected promise with rejectWith', function () {
            $exception = new Exception('test error');
            $promise = Promise::rejectWith($exception);

            expect($promise)->toRejectWith(Exception::class, 'test error');
        });
    });

    describe('complex chaining scenarios', function () {
        it('handles nested promises in then chain', function () {
            $promise = Promise::resolveWith(1)
                ->then(fn($x) => Promise::resolveWith($x * 2))
                ->then(fn($x) => Promise::resolveWith($x + 10))
                ->then(fn($x) => $x * 3);

            expect($promise)->toResolveWith(36); // ((1 * 2) + 10) * 3
        });

        it('handles error recovery in chain', function () {
            $promise = Promise::resolveWith('start')
                ->then(fn() => throw new Exception('error'))
                ->catch(fn() => 'recovered')
                ->then(fn($x) => $x . ' and continued');

            expect($promise)->toResolveWith('recovered and continued');
        });

        it('handles multiple catch handlers', function () {
            $promise = Promise::rejectWith(new RuntimeException('runtime error'))
                ->catch(function (InvalidArgumentException $e) {
                    return 'caught invalid';
                })
                ->catch(function (RuntimeException $e) {
                    return 'caught runtime';
                });

            expect($promise)->toResolveWith('caught runtime');
        });
    });

    describe('memory management', function () {
        it('clears callbacks after settlement', function () {
            $promise = new Promise();

            // Add some callbacks
            $promise->then(fn($x) => $x);
            $promise->catch(fn($x) => $x);
            $promise->finally(fn() => null);

            // Resolve the promise (which should clear callbacks)
            $promise->resolve('value');

            expect($promise)->toResolveWith('value');
            // Callbacks should be cleared (can't directly test this, but it's internal behavior)
        });
    });
});
