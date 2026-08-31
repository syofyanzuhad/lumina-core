<?php

use Carbon\Carbon;
use Lumina\Core\Support\DateRangeHelper;
use Lumina\Core\Tests\TestCase;

uses(TestCase::class);

test('it resolves today period correctly', function () {
    Carbon::setTestNow('2026-08-31 15:30:00');

    [$start, $end] = DateRangeHelper::resolve('today');

    expect($start->toDateTimeString())->toBe('2026-08-31 00:00:00')
        ->and($end->toDateTimeString())->toBe('2026-08-31 23:59:59');

    Carbon::setTestNow();
});

test('it resolves 7d period correctly', function () {
    Carbon::setTestNow('2026-08-31 15:30:00');

    [$start, $end] = DateRangeHelper::resolve('7d');

    expect($start->toDateTimeString())->toBe('2026-08-25 00:00:00')
        ->and($end->toDateTimeString())->toBe('2026-08-31 23:59:59');

    Carbon::setTestNow();
});

test('it resolves 30d default period correctly', function () {
    Carbon::setTestNow('2026-08-31 15:30:00');

    [$start, $end] = DateRangeHelper::resolve('30d');

    expect($start->toDateTimeString())->toBe('2026-08-02 00:00:00')
        ->and($end->toDateTimeString())->toBe('2026-08-31 23:59:59');

    [$startFallback, $endFallback] = DateRangeHelper::resolve('unknown');

    expect($startFallback->toDateTimeString())->toBe('2026-08-02 00:00:00')
        ->and($endFallback->toDateTimeString())->toBe('2026-08-31 23:59:59');

    Carbon::setTestNow();
});

test('it resolves custom period when both start and end dates are provided', function () {
    [$start, $end] = DateRangeHelper::resolve('custom', '2026-01-10', '2026-01-20');

    expect($start->toDateTimeString())->toBe('2026-01-10 00:00:00')
        ->and($end->toDateTimeString())->toBe('2026-01-20 23:59:59');
});

test('it falls back to 30d if custom period is missing start or end date', function () {
    Carbon::setTestNow('2026-08-31 15:30:00');

    [$start, $end] = DateRangeHelper::resolve('custom', '2026-01-10', null);

    expect($start->toDateTimeString())->toBe('2026-08-02 00:00:00')
        ->and($end->toDateTimeString())->toBe('2026-08-31 23:59:59');

    Carbon::setTestNow();
});
