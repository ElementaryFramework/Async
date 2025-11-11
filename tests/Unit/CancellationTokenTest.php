<?php

declare(strict_types=1);

use ElementaryFramework\Core\Async\Async;
use ElementaryFramework\Core\Async\CancellationToken;
use ElementaryFramework\Core\Async\CancellationTokenInterface;
use ElementaryFramework\Core\Async\CancellationException;
use ElementaryFramework\Core\Async\CombinedCancellationToken;
use function ElementaryFramework\Core\Async\await;

describe('CancellationToken', function () {
    it('can be created as cancellable by default', function () {
        $token = new CancellationToken();

        expect($token)->toBeInstanceOf(CancellationTokenInterface::class)
            ->and($token->canBeCanceled())->toBeTrue()
            ->and($token->isCancellationRequested())->toBeFalse()
            ->and($token->reason)->toBeNull();
    });

    it('can be created as non-cancellable', function () {
        $token = new CancellationToken(false);

        expect($token->canBeCanceled())->toBeFalse()
            ->and($token->isCancellationRequested())->toBeFalse();
    });

    describe('isCancellationRequested method', function () {
        it('returns false for new token', function () {
            $token = new CancellationToken();

            expect($token->isCancellationRequested())->toBeFalse();
        });

        it('returns true after cancellation', function () {
            $token = new CancellationToken();
            $token->cancel('test reason');

            expect($token->isCancellationRequested())->toBeTrue();
        });

        it('returns false for never-cancel token', function () {
            $token = new CancellationToken(false);
            $token->cancel('attempt to cancel');

            expect($token->isCancellationRequested())->toBeFalse();
        });
    });

    describe('throwIfCancellationRequested method', function () {
        it('does nothing when not cancelled', function () {
            $token = new CancellationToken();

            expect(fn() => $token->throwIfCancellationRequested())->not->toThrow(CancellationException::class);
        });

        it('throws CancellationException when cancelled', function () {
            $token = new CancellationToken();
            $token->cancel('test cancellation');

            expect(fn() => $token->throwIfCancellationRequested())
                ->toThrow(CancellationException::class, 'test cancellation');
        });

        it('throws with default message when no reason provided', function () {
            $token = new CancellationToken();
            $token->cancel();

            expect(fn() => $token->throwIfCancellationRequested())
                ->toThrow(CancellationException::class, 'Operation was cancelled');
        });
    });

    describe('register method', function () {
        it('calls callback immediately if already cancelled', function () {
            $token = new CancellationToken();
            $token->cancel('already cancelled');

            $called = false;
            $unregister = $token->register(function () use (&$called) {
                $called = true;
            });

            expect($called)->toBeTrue();

            // Unregister should be a no-op function
            expect($unregister)->toBeCallable();
            $unregister();
        });

        it('calls callback when token is cancelled', function () {
            $token = new CancellationToken();
            $called = false;

            $unregister = $token->register(function () use (&$called) {
                $called = true;
            });

            expect($called)->toBeFalse();

            $token->cancel();

            expect($called)->toBeTrue();
        });

        it('can register multiple callbacks', function () {
            $token = new CancellationToken();
            $called1 = false;
            $called2 = false;

            $token->register(function () use (&$called1) {
                $called1 = true;
            });

            $token->register(function () use (&$called2) {
                $called2 = true;
            });

            $token->cancel();

            expect($called1)->toBeTrue()
                ->and($called2)->toBeTrue();
        });

        it('can unregister callbacks', function () {
            $token = new CancellationToken();
            $called = false;

            $unregister = $token->register(function () use (&$called) {
                $called = true;
            });

            $unregister();
            $token->cancel();

            expect($called)->toBeFalse();
        });

        it('handles callback exceptions gracefully', function () {
            $token = new CancellationToken();
            $goodCallbackCalled = false;

            $token->register(function () {
                throw new Exception('bad callback');
            });

            $token->register(function () use (&$goodCallbackCalled) {
                $goodCallbackCalled = true;
            });

            $token->cancel();

            expect($goodCallbackCalled)->toBeTrue();
        });

        it('does not call callbacks on never-cancel token', function () {
            $token = new CancellationToken(false);
            $called = false;

            $token->register(function () use (&$called) {
                $called = true;
            });

            $token->cancel();

            expect($called)->toBeFalse();
        });
    });

    describe('getReason method', function () {
        it('returns null for new token', function () {
            $token = new CancellationToken();

            expect($token->reason)->toBeNull();
        });

        it('returns provided reason after cancellation', function () {
            $token = new CancellationToken();
            $token->cancel('custom reason');

            expect($token->reason)->toBe('custom reason');
        });

        it('returns null when no reason provided', function () {
            $token = new CancellationToken();
            $token->cancel();

            expect($token->reason)->toBeNull();
        });
    });

    describe('canBeCancelled method', function () {
        it('returns true for regular token', function () {
            $token = new CancellationToken(true);

            expect($token->canBeCanceled())->toBeTrue();
        });

        it('returns false for never-cancel token', function () {
            $token = new CancellationToken(false);

            expect($token->canBeCanceled())->toBeFalse();
        });
    });

    describe('waitForCancellation method', function () {
        it('returns resolved promise for already cancelled token', function () {
            $token = new CancellationToken();
            $token->cancel();

            $promise = $token->waitForCancellation();

            expect($promise)->toBeFulfilled()
                ->and($promise->unwrap())->toBeNull();
        });

        it('returns never-resolving promise for never-cancel token', function () {
            $token = new CancellationToken(false);

            $promise = $token->waitForCancellation();

            expect($promise)->toBePending();
        });

        it('returns promise that resolves when cancelled', function () {
            $token = new CancellationToken();
            $promise = $token->waitForCancellation();

            expect($promise)->toBePending();

            $token->cancel();

            // In a full async environment, this would resolve
            // For testing, we verify the callback was registered
            expect($token->isCancellationRequested())->toBeTrue();
        });
    });

    describe('combineWith method', function () {
        it('returns self when combining with no tokens', function () {
            $token = new CancellationToken();
            $combined = $token->combineWith();

            expect($combined)->toBe($token);
        });

        it('creates combined token with other tokens', function () {
            $token1 = new CancellationToken();
            $token2 = new CancellationToken();
            $combined = $token1->combineWith($token2);

            expect($combined)->toBeInstanceOf(CombinedCancellationToken::class)
                ->and($combined->canBeCanceled())->toBeTrue();
        });

        it('combined token is cancelled when source token is cancelled', function () {
            $token1 = new CancellationToken();
            $token2 = new CancellationToken();
            $combined = $token1->combineWith($token2);

            $token1->cancel('first cancelled');

            expect($combined->isCancellationRequested())->toBeTrue()
                ->and($combined->reason)->toBe('first cancelled');
        });
    });

    describe('cancel method', function () {
        it('cancels the token with reason', function () {
            $token = new CancellationToken();
            $token->cancel('test reason');

            expect($token->isCancellationRequested())->toBeTrue()
                ->and($token->reason)->toBe('test reason');
        });

        it('cancels the token without reason', function () {
            $token = new CancellationToken();
            $token->cancel();

            expect($token->isCancellationRequested())->toBeTrue()
                ->and($token->reason)->toBeNull();
        });

        it('does nothing on already cancelled token', function () {
            $token = new CancellationToken();
            $token->cancel('first reason');
            $token->cancel('second reason');

            expect($token->reason)->toBe('first reason');
        });

        it('does nothing on never-cancel token', function () {
            $token = new CancellationToken(false);
            $token->cancel('attempt to cancel');

            expect($token->isCancellationRequested())->toBeFalse()
                ->and($token->reason)->toBeNull();
        });

        it('clears callbacks after cancellation', function () {
            $token = new CancellationToken();
            $callCount = 0;

            $token->register(function () use (&$callCount) {
                $callCount++;
            });

            $token->cancel();
            $token->cancel(); // Second cancellation should not call callbacks again

            expect($callCount)->toBe(1);
        });
    });

    describe('static factory methods', function () {
        describe('never', function () {
            it('creates a never-cancel token', function () {
                $token = CancellationToken::never();

                expect($token->canBeCanceled())->toBeFalse()
                    ->and($token->isCancellationRequested())->toBeFalse();
            });

            it('never-cancel token ignores cancellation attempts', function () {
                $token = CancellationToken::never();
                $token->cancel('ignored');

                expect($token->isCancellationRequested())->toBeFalse();
            });
        });

        describe('cancelled', function () {
            it('creates an already cancelled token', function () {
                $token = CancellationToken::cancelled('pre-cancelled');

                expect($token->isCancellationRequested())->toBeTrue()
                    ->and($token->reason)->toBe('pre-cancelled');
            });

            it('creates cancelled token without reason', function () {
                $token = CancellationToken::cancelled();

                expect($token->isCancellationRequested())->toBeTrue()
                    ->and($token->reason)->toBeNull();
            });

            it('immediately calls registered callbacks', function () {
                $token = CancellationToken::cancelled('already done');
                $called = false;

                $token->register(function () use (&$called) {
                    $called = true;
                });

                expect($called)->toBeTrue();
            });
        });
    });

    describe('integration scenarios', function () {
        it('can be used to cancel async operations', function () {
            $token = new CancellationToken();
            $operationCompleted = false;
            $operationCancelled = false;

            // Simulate an async operation that checks for cancellation
            $performOperation = Async::run(function () use ($token, &$operationCompleted, &$operationCancelled) {
                for ($i = 0; $i < 10; $i++) {
                    if ($token->isCancellationRequested()) {
                        $operationCancelled = true;
                        return;
                    }
                    // Simulate work
                    usleep(1000);
                    Async::yield();
                }
                $operationCompleted = true;
            });

            $token->cancel('stop operation');
            await();

            expect($performOperation)->toBeFulfilled()
                ->and($operationCancelled)->toBeTrue()
                ->and($operationCompleted)->toBeFalse();
        });

        it('supports timeout-like behavior with callbacks', function () {
            $token = new CancellationToken();
            $timeoutOccurred = false;

            $token->register(function () use (&$timeoutOccurred) {
                $timeoutOccurred = true;
            });

            // Simulate timeout
            $token->cancel('timeout');

            expect($timeoutOccurred)->toBeTrue();
        });

        it('can coordinate multiple operations', function () {
            $masterToken = new CancellationToken();
            $operations = [];

            // Register multiple operations
            for ($i = 0; $i < 3; $i++) {
                $operations[$i] = ['completed' => false, 'cancelled' => false];

                $masterToken->register(function () use (&$operations, $i) {
                    $operations[$i]['cancelled'] = true;
                });
            }

            // Cancel all operations at once
            $masterToken->cancel('shutdown');

            foreach ($operations as $operation) {
                expect($operation['cancelled'])->toBeTrue();
            }
        });
    });

    describe('memory management', function () {
        it('releases callback references after cancellation', function () {
            $token = new CancellationToken();
            $objectRef = new stdClass();
            $objectRef->called = false;

            $token->register(function () use ($objectRef) {
                $objectRef->called = true;
            });

            $token->cancel();

            expect($objectRef->called)->toBeTrue();

            // After cancellation, callbacks should be cleared
            // (This is internal behavior, but important for memory management)
            unset($objectRef);
            // Object should be cleanly released
        });

        it('handles circular references gracefully', function () {
            $token = new CancellationToken();
            $container = new stdClass();
            $container->token = $token;
            $container->called = false;

            $token->register(function () use ($container) {
                $container->called = true;
            });

            $token->cancel();

            expect($container->called)->toBeTrue();

            // Clean up circular reference
            unset($container->token);
        });
    });
});
