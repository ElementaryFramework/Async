<?php

declare(strict_types=1);

use ElementaryFramework\Core\Async\Async;
use ElementaryFramework\Core\Async\CancellationException;
use ElementaryFramework\Core\Async\PromiseInterface;

use function ElementaryFramework\Core\Async\promisify;
use function ElementaryFramework\Core\Async\async;
use function ElementaryFramework\Core\Async\delay;
use function ElementaryFramework\Core\Async\deferred;
use function ElementaryFramework\Core\Async\all;
use function ElementaryFramework\Core\Async\race;
use function ElementaryFramework\Core\Async\any;
use function ElementaryFramework\Core\Async\allSettled;
use function ElementaryFramework\Core\Async\timeout;
use function ElementaryFramework\Core\Async\pool;
use function ElementaryFramework\Core\Async\retry;
use function ElementaryFramework\Core\Async\sequence;
use function ElementaryFramework\Core\Async\schedule;
use function ElementaryFramework\Core\Async\setTimeout;
use function ElementaryFramework\Core\Async\setInterval;
use function ElementaryFramework\Core\Async\clearTimer;
use function ElementaryFramework\Core\Async\await;
use function ElementaryFramework\Core\Async\debounce;
use function ElementaryFramework\Core\Async\throttle;

describe('Global async helper functions', function () {
    test('promisify resolves and rejects', function () {
        $p1 = promisify(fn () => 42);
        $this->assertPromiseResolvesWith($p1, 42);

        $p2 = promisify(function () {
            throw new RuntimeException('boom');
        });
        $this->assertPromiseRejectsWith($p2, RuntimeException::class, 'boom');
    });

    test('async and delay work together', function () {
        $p = async(fn () => 7)->then(fn ($v) => $v + 1);
        $this->assertPromiseResolvesWith($p, 8);

        $p2 = delay(5, 'ok');
        $this->assertPromiseResolvesWith($p2, 'ok');
    });

    test('deferred can resolve and reject', function () {
        $d = deferred();
        $p = $d->promise();
        $d->resolve('yes');
        $this->assertPromiseResolvesWith($p, 'yes');

        $d2 = deferred();
        $p2 = $d2->promise();
        $d2->reject(new InvalidArgumentException('no'));
        $this->assertPromiseRejectsWith($p2, InvalidArgumentException::class, 'no');
    });

    test('all, race, any, and allSettled behave correctly', function () {
        $p1 = delay(5, 1);
        $p2 = delay(1, 2);

        $all = all([$p1, $p2]);

        $r = race([$p1, $p2]);

        $rej = async(function () { throw new RuntimeException('x'); });
        $anyP = any([$rej, $p1]);

        $settled = allSettled([$p1, $rej]);

        await();

        $this->assertPromiseEventuallySettles($settled, 'fulfilled');
        $this->assertPromiseResolvesWith($all, [1, 2]);
        $this->assertPromiseResolvesWith($r, 2);
        $this->assertPromiseResolvesWith($anyP, 1);

        $result = $settled->unwrap();
        expect($result)->toBeArray()->toHaveCount(2);
    });

    test('timeout cancels long operation', function () {
        $long = function() {
            do {
                usleep(100);
                Async::yield();
            } while (true);
        };
        $t = timeout($long, 500);
        await();
        $this->assertPromiseRejectsWith($t, CancellationException::class);
    });

    test('pool runs tasks with concurrency and sequence preserves order', function () {
        $tasks = [
            fn () => delay(5, 'a'),
            fn () => delay(5, 'b'),
            fn () => delay(5, 'c'),
        ];

        $pooled = pool($tasks, 2);
        await();

        $this->assertPromiseResolvesWith($pooled, ['a','b','c']);

        $seq = sequence($tasks);
        await();

        $this->assertPromiseResolvesWith($seq, ['a','b','c']);
    });

    test('retry retries failing operation and eventually succeeds', function () {
        $attempts = 0;
        $op = function () use (&$attempts): PromiseInterface {
            return async(function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new RuntimeException('fail');
                }
                return 'ok';
            });
        };

        $p = retry($op, maxAttempts: 3, baseDelay: 100, maxDelay: 5000);
        await();

        $this->assertPromiseResolvesWith($p, 'ok');
        expect($attempts)->toBeGreaterThanOrEqual(3);
    });

    test('schedule and timers wrappers call into EventLoop', function () {
        $called = false;
        schedule(function () use (&$called) { $called = true; });
        // Let one tick happen
        $this->runEventLoopBriefly(0.02);
        expect($called)->toBeTrue();

        $flag = false;
        $id = setTimeout(function () use (&$flag) { $flag = true; }, 5);
        $this->runEventLoopBriefly(0.05);
        expect($flag)->toBeTrue()->and($id)->toBeInt();

        $counter = 0;
        $iid = setInterval(function () use (&$counter) { $counter++; }, 5);
        // Clear before it fires to just hit wrapper
        clearTimer($iid);
        expect($counter)->toBe(0);
    });

    test('await waits for completion when supported', function () {
        if (!class_exists(Fiber::class)) {
            $this->markTestSkipped('This test requires PHP Fibers support');
        }

        $p = delay(5, 'done');
        await();
        expect($p->isFulfilled())->toBeTrue();
    });

    test('debounce wrapper returns callable and resolves once after quiet period', function () {
        $calls = 0;
        $op = function () use (&$calls) {
            $calls++;
            return delay(1, 'deb');
        };

        $deb = debounce($op, 5);
        $p1 = $deb();
        $p2 = $deb(); // should debounce the first

        // Allow time for the debounced call to fire
        $this->runEventLoopBriefly(0.05);

        // Last promise should be fulfilled and only one call performed
        $this->assertPromiseResolvesWith($p2, 'deb');
        expect($calls)->toBe(1);
    });

    test('throttle wrapper returns callable and executes', function () {
        $calls = 0;
        $op = function () use (&$calls) {
            $calls++;
            return delay(1, 'thr');
        };

        $thr = throttle($op, 5);
        $p = $thr();
        $this->runEventLoopBriefly(0.02);
        $this->assertPromiseEventuallySettles($p, 'fulfilled');
        expect($calls)->toBeGreaterThanOrEqual(1);
    });
});
