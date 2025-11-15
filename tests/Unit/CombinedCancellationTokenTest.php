<?php

declare(strict_types=1);

use ElementaryFramework\Core\Async\CancellationToken;
use ElementaryFramework\Core\Async\CancellationTokenSource;
use ElementaryFramework\Core\Async\CombinedCancellationToken;

describe('CombinedCancellationToken', function () {
    it('is cancelled when any source token cancels and invokes callbacks', function () {
        $a = new CancellationTokenSource();
        $b = new CancellationTokenSource();

        $combined = CombinedCancellationToken::create($a->getToken(), $b->getToken());

        $called = 0;
        $combined->register(function () use (&$called) { $called++; });

        expect($combined->isCancellationRequested())->toBeFalse()
            ->and($combined->canBeCanceled())->toBeTrue();

        $b->cancel('stop');

        expect($combined->isCancellationRequested())->toBeTrue()
            ->and($called)->toBe(1);

        // register after cancel should invoke immediately
        $immediate = 0;
        $combined->register(function () use (&$immediate) { $immediate++; });
        expect($immediate)->toBe(1);

        // throwIfCancellationRequested should throw
        expect(fn () => $combined->throwIfCancellationRequested())
            ->toThrow(\ElementaryFramework\Core\Async\CancellationException::class);
    });

    it('waitForCancellation resolves when cancelled; never resolves when cannot be canceled', function () {
        $src = new CancellationTokenSource();
        $combined = CombinedCancellationToken::create($src->getToken());

        $p = $combined->waitForCancellation();
        expect($p->isPending())->toBeTrue();

        $src->cancel('go');
        $this->runEventLoopBriefly(0.02);
        expect($p->isFulfilled())->toBeTrue();

        $never = CombinedCancellationToken::create(CancellationToken::never());
        $p2 = $never->waitForCancellation();
        expect($p2->isPending())->toBeTrue();
    });

    it('combineWith merges additional tokens', function () {
        $a = new CancellationTokenSource();
        $b = new CancellationTokenSource();
        $c = new CancellationTokenSource();

        $combined = CombinedCancellationToken::create($a->getToken());
        $merged = $combined->combineWith($b->getToken(), $c->getToken());

        // Cancel one of the later tokens and expect merged to be cancelled
        $c->cancel('later');
        if (method_exists($merged, 'waitForCancellation')) {
            $this->runEventLoopBriefly(0.02);
            expect($merged->isCancellationRequested())->toBeTrue();
        }
    });
});
