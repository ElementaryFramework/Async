<?php

declare(strict_types=1);

namespace Tests;

use ElementaryFramework\Core\Async\Async;
use ElementaryFramework\Core\Async\EventLoop;
use ElementaryFramework\Core\Async\PromiseInterface;
use Fiber;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Throwable;
use function ElementaryFramework\Core\Async\async;

/**
 * Base test case for all async library tests.
 *
 * Provides common setup and utilities for testing async operations.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Set up before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the event loop singleton for each test
        EventLoop::reset();
        Async::startEventLoop();
    }

    /**
     * Clean up after each test.
     */
    protected function tearDown(): void
    {
        // Stop any running event loop
        Async::stopEventLoop();

        // Reset the event loop singleton
        EventLoop::reset();

        parent::tearDown();
    }

    /**
     * Skip test if Fibers are not supported.
     */
    protected function requiresFibers(): void
    {
        if (!class_exists(Fiber::class)) {
            $this->markTestSkipped('This test requires PHP Fibers support');
        }
    }

    /**
     * Skip test if PCNTL extension is not available.
     */
    protected function requiresPCNTL(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('This test requires PCNTL extension');
        }
    }

    /**
     * Create a mock promise that behaves like a real async operation.
     *
     * @param mixed $resolveValue Value to resolve with
     * @param float $delay Delay in milliseconds before resolution
     * @return PromiseInterface
     */
    protected function createDelayedPromise(mixed $resolveValue, float $delay = 10): PromiseInterface
    {
        return async(function() use ($resolveValue, $delay) {
            if ($delay > 0) {
                usleep((int)($delay * 1_000_000));
            }
            return $resolveValue;
        });
    }

    /**
     * Create a mock promise that rejects after a delay.
     *
     * @param Throwable $exception Exception to reject with
     * @param float $delay Delay in milliseconds before rejection
     * @return PromiseInterface
     */
    protected function createDelayedRejection(Throwable $exception, float $delay = 10): PromiseInterface
    {
        return async(function() use ($exception, $delay) {
            if ($delay > 0) {
                usleep((int)($delay * 1_000_000));
            }
            throw $exception;
        });
    }

    /**
     * Run the event loop for a short period to allow async operations to complete.
     *
     * @param float $maxDuration Maximum duration to run the loop
     */
    protected function runEventLoopBriefly(float $maxDuration = 0.1): void
    {
        if (!class_exists(Fiber::class)) {
            return; // Skip if Fibers not supported
        }

        $eventLoop = EventLoop::getInstance();
        $startTime = microtime(true);

        while ($eventLoop->hasPendingWork() && (microtime(true) - $startTime) < $maxDuration) {
            $eventLoop->tick();
            usleep(1000); // 1ms
        }
    }

    /**
     * Assert that a promise eventually settles to a specific state.
     *
     * @param PromiseInterface $promise
     * @param string $expectedState 'fulfilled' or 'rejected'
     * @param int $timeoutMs Maximum time to wait in milliseconds
     */
    protected function assertPromiseEventuallySettles(
        PromiseInterface $promise,
        string $expectedState,
        int $timeoutMs = 100
    ): void {
        $startTime = microtime(true);
        $timeout = $timeoutMs / 1000;

        while ((microtime(true) - $startTime) < $timeout) {
            $this->runEventLoopBriefly(0.01);

            if ($promise->isSettled()) {
                break;
            }

            usleep(1000); // 1ms
        }

        $this->assertTrue($promise->isSettled(), 'Promise did not settle within timeout');

        if ($expectedState === 'fulfilled') {
            $this->assertTrue($promise->isFulfilled(), 'Expected promise to be fulfilled');
        } else {
            $this->assertTrue($promise->isRejected(), 'Expected promise to be rejected');
        }
    }

    /**
     * Assert that a promise resolves with a specific value.
     *
     * @param PromiseInterface $promise
     * @param mixed $expectedValue
     * @param int $timeoutMs
     */
    protected function assertPromiseResolvesWith(
        PromiseInterface $promise,
        mixed $expectedValue,
        int $timeoutMs = 100
    ): void {
        $this->assertPromiseEventuallySettles($promise, 'fulfilled', $timeoutMs);
        $this->assertEquals($expectedValue, $promise->unwrap());
    }

    /**
     * Assert that a promise rejects with a specific exception type.
     *
     * @param PromiseInterface $promise
     * @param string $expectedExceptionClass
     * @param string|null $expectedMessage
     * @param int $timeoutMs
     */
    protected function assertPromiseRejectsWith(
        PromiseInterface $promise,
        string $expectedExceptionClass,
        ?string $expectedMessage = null,
        int $timeoutMs = 100
    ): void {
        $this->assertPromiseEventuallySettles($promise, 'rejected', $timeoutMs);

        $reason = $promise->getReason();
        $this->assertInstanceOf($expectedExceptionClass, $reason);

        if ($expectedMessage !== null) {
            $this->assertEquals($expectedMessage, $reason->getMessage());
        }
    }
}
