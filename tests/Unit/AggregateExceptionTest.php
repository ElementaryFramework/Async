<?php

declare(strict_types=1);

use ElementaryFramework\Core\Async\AggregateException;

describe('AggregateException', function () {
    it('exposes inner exceptions and their messages', function () {
        $e1 = new RuntimeException('first');
        $e2 = new InvalidArgumentException('second');

        $agg = new AggregateException('many', [$e1, $e2]);

        expect($agg->hasInnerExceptions())->toBeTrue()
            ->and($agg->getInnerExceptionCount())->toBe(2)
            ->and($agg->getInnerExceptions())->toBe([$e1, $e2])
            ->and($agg->getInnerException(0))->toBe($e1)
            ->and($agg->getInnerException(1))->toBe($e2)
            ->and($agg->getInnerException(99))->toBeNull()
            ->and($agg->getInnerExceptionMessages())->toBe(['first', 'second']);

        $str = (string)$agg;
        expect($str)->toContain('Inner Exceptions:')
            ->and($str)->toContain('[0] '.get_class($e1).': first')
            ->and($str)->toContain('[1] '.get_class($e2).': second');
    });

    it('formats empty inner exceptions string', function () {
        $agg = new AggregateException('none', []);
        expect($agg->getInnerExceptionsAsString())->toBe('No inner exceptions');
    });

    it('creates from mixed array and flattens nested aggregates', function () {
        $inner = new AggregateException('inner', [new LogicException('L1')]);
        $agg = new AggregateException('outer', [new RuntimeException('R1'), $inner]);

        $flat = $agg->flatten();
        expect($flat->getInnerExceptionCount())->toBe(2)
            ->and($flat->getInnerException(0))->toBeInstanceOf(RuntimeException::class)
            ->and($flat->getInnerException(1))->toBeInstanceOf(LogicException::class);

        $from = AggregateException::fromArray([
            new DomainException('D1'),
            'oops',
        ], 'mixed');

        expect($from->getInnerExceptionCount())->toBe(2)
            ->and($from->filterByType(DomainException::class))->toHaveCount(1)
            ->and($from->containsType(DomainException::class))->toBeTrue()
            ->and($from->containsType(OutOfBoundsException::class))->toBeFalse();

        $messages = $from->getInnerExceptionMessages();
        expect($messages[0])->toBe('D1')
            ->and($messages[1])->toContain('Exception at index 1: oops');
    });
});
