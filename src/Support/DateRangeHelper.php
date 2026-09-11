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

    /**
     * Resolve the previous equivalent period for comparison indicators.
     *
     * Returns the window immediately before the resolved period, with the
     * same duration, so callers can compute percent-change badges.
     *
     * @return array{CarbonInterface, CarbonInterface}
     */
    public static function previousPeriod(?string $period = '30d', ?string $startDate = null, ?string $endDate = null): array
    {
        if ($period === 'today') {
            return [
                now()->subDay()->startOfDay(),
                now()->subDay()->endOfDay(),
            ];
        }

        if ($period === '7d') {
            return [
                now()->subDays(13)->startOfDay(),
                now()->subDays(7)->endOfDay(),
            ];
        }

        if ($period === 'custom' && $startDate && $endDate) {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
            $days = (int) $start->diffInDays($end) + 1;

            return [
                $start->copy()->subDays($days)->startOfDay(),
                $start->copy()->subDay()->endOfDay(),
            ];
        }

        // Default: previous 30-day window
        return [
            now()->subDays(59)->startOfDay(),
            now()->subDays(30)->endOfDay(),
        ];
    }
}
