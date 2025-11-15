<?php

declare(strict_types=1);

use ElementaryFramework\Core\Async\CancellationToken;
use ElementaryFramework\Core\Async\CancellationTokenInterface;
use ElementaryFramework\Core\Async\CancellationTokenSource;

describe('CancellationTokenSource', function () {
    it('auto-cancels with timeout', function () {
        $source = CancellationTokenSource::withTimeout(10);
        $token = $source->getToken();
        expect($token->isCancellationRequested())->toBeFalse();

        // Allow the scheduled cancellation to run
        \Tests\TestCase::assertTrue(true); // ensure TestCase loaded
        $this->runEventLoopBriefly(0.05);

        expect($source->isCancellationRequested())->toBeTrue()
            ->and($token->isCancellationRequested())->toBeTrue();
    });

    it('combines tokens and cancels when any source cancels', function () {
        $a = new CancellationTokenSource();
        $b = new CancellationTokenSource();

        $combined = CancellationTokenSource::combineTokens($a->getToken(), $b->getToken());
        $ct = $combined->getToken();

        expect($ct->isCancellationRequested())->toBeFalse();
        $a->cancel('stop');
        expect($ct->isCancellationRequested())->toBeTrue();
    });

    it('creates never and cancelled sources', function () {
        $never = CancellationTokenSource::never();
        expect($never->getToken()->canBeCanceled())->toBeFalse()
            ->and($never->isCancellationRequested())->toBeFalse();

        $cancelled = CancellationTokenSource::cancelled('done');
        expect($cancelled->isCancellationRequested())->toBeTrue()
            ->and($cancelled->getToken()->isCancellationRequested())->toBeTrue();
    });

    it('throws when disposed and methods are used', function () {
        $src = new CancellationTokenSource();
        $src->dispose();

        expect(fn () => $src->getToken())->toThrow(RuntimeException::class)
            ->and(fn () => $src->cancel())->toThrow(RuntimeException::class)
            ->and(fn () => $src->isCancellationRequested())->toThrow(RuntimeException::class);
    });

    it('creates source withSignal without errors (PCNTL guarded)', function () {
        // Use a common signal number; handler registration is guarded internally
        $src = CancellationTokenSource::withSignal(\defined('SIGINT') ? \SIGINT : 2);
        expect($src->getToken())->toBeInstanceOf(CancellationTokenInterface::class)
            ->and($src->isCancellationRequested())->toBeFalse();
    });
});
