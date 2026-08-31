<?php

namespace Lumina\Core\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class DateRangeHelper
{
    /**
     * Resolve start and end dates for analytics queries based on period.
     *
     * @return array{CarbonInterface, CarbonInterface}
     */
    public static function resolve(?string $period = '30d', ?string $startDate = null, ?string $endDate = null): array
    {
        if ($period === 'today') {
            return [
                now()->startOfDay(),
                now()->endOfDay(),
            ];
        }

        if ($period === '7d') {
            return [
                now()->subDays(6)->startOfDay(),
                now()->endOfDay(),
            ];
        }

        if ($period === 'custom' && $startDate && $endDate) {
            return [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ];
        }

        return [
            now()->subDays(29)->startOfDay(),
            now()->endOfDay(),
        ];
    }
}
