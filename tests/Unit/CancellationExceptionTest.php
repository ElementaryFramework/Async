<?php

declare(strict_types=1);

use ElementaryFramework\Core\Async\CancellationException;

describe('CancellationException', function () {
    it('creates timeout exception with proper message and code', function () {
        $e = CancellationException::timeout(123.5);
        expect($e)->toBeInstanceOf(CancellationException::class)
            ->and($e->getMessage())->toBe('Operation timed out after 123.5 milliseconds')
            ->and($e->getCode())->toBe(0);
    });

    it('creates manual cancellation with custom reason', function () {
        $e = CancellationException::manual('Stopped by user');
        expect($e->getMessage())->toBe('Stopped by user')
            ->and($e->getCode())->toBe(0);
    });

    it('creates signal cancellation with signal code', function () {
        $e = CancellationException::signal(15);
        expect($e->getMessage())->toBe('Operation cancelled by signal 15')
            ->and($e->getCode())->toBe(15);
    });
});
