<?php

declare(strict_types=1);

use App\Support\Math\Decimal;

test('adds money without floating point drift', function () {
    expect(Decimal::of('0.1')->plus('0.2')->toMoney())->toBe('0.30')
        ->and(Decimal::sum(['1000.10', '2000.20', '0.70'])->toMoney())->toBe('3001.00');
});

test('rounds half up to two decimals', function () {
    expect(Decimal::of('10.005')->toMoney())->toBe('10.01')
        ->and(Decimal::of('10.004')->toMoney())->toBe('10.00')
        ->and(Decimal::of('-10.005')->toMoney())->toBe('-10.01');
});

test('computes percentages', function () {
    expect(Decimal::of('100000')->percentOf('18')->toMoney())->toBe('18000.00')
        ->and(Decimal::of('999.99')->percentOf('9')->toMoney())->toBe('90.00');
});

test('quantity times rate is exact', function () {
    expect(Decimal::of('12.3456')->times('789.1234')->toString())->toBe('9742.20184704')
        ->and(Decimal::of('12.3456')->times('789.1234')->toMoney())->toBe('9742.20');
});

test('rejects floats and invalid strings', function () {
    expect(fn () => Decimal::of(0.1))->toThrow(TypeError::class);
    expect(fn () => Decimal::of('abc'))->toThrow(InvalidArgumentException::class);
});

test('treats null and empty string as zero', function () {
    expect(Decimal::of(null)->isZero())->toBeTrue()
        ->and(Decimal::of('')->isZero())->toBeTrue();
});

test('division by zero throws', function () {
    expect(fn () => Decimal::of('10')->dividedBy('0'))->toThrow(InvalidArgumentException::class);
});

test('comparisons', function () {
    $a = Decimal::of('100.00');

    expect($a->equals('100'))->toBeTrue()
        ->and($a->greaterThan('99.99'))->toBeTrue()
        ->and($a->lessThan('100.01'))->toBeTrue()
        ->and(Decimal::of('-1')->isNegative())->toBeTrue();
});
