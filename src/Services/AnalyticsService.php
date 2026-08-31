<?php

namespace Lumina\Core\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lumina\Core\Models\Event;
use Lumina\Core\Models\Site;
use Lumina\Core\Support\CountryHelper;
use Lumina\Core\Support\ReferrerHelper;

/**
 * Analytics metrics service for Lumina.
 *
 * Date-range convention: all date ranges are **inclusive on both ends**
 * (`created_at >= start AND created_at <= end`). Callers are expected to
 * normalize boundaries to `startOfDay()`/`endOfDay()` before calling into this
 * service (see `resolveDateRange` in the dashboard/share controllers).
 *
 * Identity & sessions: rows ingested before the identity migration carry only
 * `visitor_hash`; newer rows also carry `visitor_id` and `session_id`. All
 * visitor-level metrics use `COALESCE(visitor_id, visitor_hash)`, and bounce
 * rate / average visit duration group by `COALESCE(session_id, visitor_hash)`
 * — genuinely session-based for new data, with a documented visitor-level
 * fallback for legacy rows that have no session identity.
 *
 * Goal semantics: `completions` counts raw goal events (GA4-style), while
 * `conversion_rate` is `unique converters / unique visitors`, with **both**
 * sides scoped to the active filters. A visitor completing the same goal
 * repeatedly therefore never inflates conversion rate. Goal matching uses the
 * same `clean_path` semantics as page analytics (with a legacy fallback for
 * rows ingested before the `clean_path` column existed).
 *
 * Caching: every metric routes through `rememberCache()` and is tagged with
 * `lumina:site:{id}` when the driver supports tags; `clearCache()` flushes
 * those tags. On drivers without tag support (file, database), invalidation
 * falls back to forgetting known keys for common periods, and the five
 * parameterized custom-event metrics additionally use a short TTL to bound
 * staleness (see `clearCache()`).
 */
class AnalyticsService
{
    /**
     * Cache TTL in seconds (default: 60).
     */
    protected int $ttl = 60;

    /**
     * Short TTL in seconds for parameterized custom-event metrics on cache
     * drivers that do not support tags. Their keys embed event names, property
     * keys, and limits, so the untagged `clearCache()` fallback loop cannot
     * enumerate them — a short TTL bounds staleness instead. Deliberate
     * tradeoff, not an oversight.
     */
    protected int $shortTtl = 15;

    /**
     * SQL expression resolving the normalized page path used by page analytics,
     * goal matching, and the path filter. Prefers the `clean_path` column (set
     * at ingestion) and falls back to the raw `path` with the query string
     * stripped for legacy rows where `clean_path` is still NULL.
     *
     * @return literal-string
     */
    protected function pathExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "COALESCE(clean_path, CASE WHEN instr(path, '?') > 0 THEN substr(path, 1, instr(path, '?') - 1) ELSE path END)"
            : 'COALESCE(clean_path, SUBSTRING_INDEX(path, \'?\', 1))';
    }

    /**
     * SQL expression extracting the custom event name from the `metadata` JSON
     * column, with per-driver JSON path syntax.
     *
     * @return literal-string
     */
    protected function eventNameExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "json_extract(metadata, '$.name')"
            : "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.name'))";
    }

    /**
     * SQL expression formatting a timestamp as a day-level date string
     * (identical semantics to `getDailyPageviews`).
     *
     * @return literal-string
     */
    protected function dateExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', created_at)"
            : 'DATE(created_at)';
    }

    /**
     * SQL expression resolving the canonical visitor identity. New rows carry
     * `visitor_id` (client-provided opaque ID or stable salted hash); legacy
     * rows fall back to `visitor_hash`.
     *
     * @return literal-string
     */
    protected function visitorExpression(): string
    {
        return 'COALESCE(visitor_id, visitor_hash)';
    }

    /**
     * SQL expression resolving the session grouping key. Rows with a real
     * `session_id` are grouped per session; legacy rows (no session identity)
     * degrade to one "session" per visitor, matching historical behavior.
     *
     * @return literal-string
     */
    protected function sessionExpression(): string
    {
        return 'COALESCE(session_id, visitor_hash)';
    }

    /**
     * Apply the active dashboard filters to an events query.
     *
     * @param  Builder<Event>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Event>
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['path'])) {
            $query->whereRaw($this->pathExpression().' = ?', [$filters['path']]);
        }
        if (! empty($filters['referrer'])) {
            $query->where('referrer', $filters['referrer']);
        }
        if (! empty($filters['country'])) {
            $query->where('country_code', $filters['country']);
        }
        if (! empty($filters['browser'])) {
            $query->where('browser', $filters['browser']);
        }
        if (! empty($filters['os'])) {
            $query->where('os', $filters['os']);
        }
        if (! empty($filters['device'])) {
            $query->where('device_type', $filters['device']);
        }
        if (! empty($filters['utm_campaign'])) {
            $query->where('utm_campaign', $filters['utm_campaign']);
        }

        return $query;
    }

    /**
     * Clear cached analytics metrics for a specific site.
     */
    public function clearCache(Site $site): void
    {
        if (Cache::supportsTags()) {
            Cache::tags(["lumina:site:{$site->id}"])->flush();

            return;
        }

        // Fallback for cache drivers without tag support (file, database).
        // Best-effort: only common periods and the fixed metric keys below can
        // be forgotten. Filtered variants and the five parameterized
        // custom-event metrics (custom_event_summary, custom_event_timeline,
        // custom_event_property_keys, custom_event_property_breakdown,
        // custom_event_logs) embed arbitrary parameters in their keys and cannot
        // be enumerated here — those rely on `$shortTtl` to bound staleness.
        $commonPeriods = [
            [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            [now()->subDays(29)->startOfDay(), now()->endOfDay()],
            [now()->startOfDay(), now()->endOfDay()],
        ];

        $metrics = [
            'pageviews',
            'unique_visitors',
            'daily_pageviews',
            'top_pages_10',
            'top_referrers_10',
            'custom_events_10',
            'device_breakdown',
            'top_browsers_10',
            'top_os_10',
            'top_countries_10',
            'custom_events_list',
            'custom_event_summary',
            'custom_event_timeline',
            'custom_event_property_keys',
            'custom_event_property_breakdown',
            'custom_event_logs',
            'goals',
        ];

        foreach ($commonPeriods as [$start, $end]) {
            foreach ($metrics as $metric) {
                Cache::forget($this->cacheKey($site->id, $metric, $start, $end));
            }
        }
    }

    /**
     * Get total pageviews for site and date range.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getPageviews(Site $site, CarbonInterface $start, CarbonInterface $end, array $filters = []): int
    {
        $cacheKey = $this->cacheKey($site->id, 'pageviews', $start, $end, $filters);

        return (int) $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $filters) {
            return Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->count();
        });
    }

    /**
     * Get unique visitors count for site and date range.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getUniqueVisitors(Site $site, CarbonInterface $start, CarbonInterface $end, array $filters = []): int
    {
        $cacheKey = $this->cacheKey($site->id, 'unique_visitors', $start, $end, $filters);

        return (int) $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $filters) {
            if (empty($filters)) {
                return (int) DB::table('daily_visitor_stats')
                    ->where('site_id', $site->id)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->distinct('visitor_hash')
                    ->count('visitor_hash');
            }

            return (int) Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->distinct()
                ->count(DB::raw($this->visitorExpression()));
        });
    }

    /**
     * Get top pages for site and date range.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getTopPages(Site $site, CarbonInterface $start, CarbonInterface $end, int $limit = 10, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey($site->id, "top_pages_{$limit}", $start, $end, $filters);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $limit, $filters) {
            $totalPageviews = $this->getPageviews($site, $start, $end, $filters);

            $pathExpr = DB::raw($this->pathExpression().' as target_path');

            $results = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->select($pathExpr, DB::raw('count(*) as count'))
                ->groupBy('target_path')
                ->orderByDesc('count')
                ->orderBy('target_path')
                ->limit($limit)
                ->get();

            return $results->map(function ($row) use ($totalPageviews) {
                $count = (int) $row->count;

                return [
                    'path' => (string) $row->target_path,
                    'count' => $count,
                    'percentage' => $totalPageviews > 0 ? round(($count / $totalPageviews) * 100, 1) : 0.0,
                ];
            })->toArray();
        });

        return collect($data ?? []);
    }

    /**
     * Get top referrers for site and date range.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getTopReferrers(Site $site, CarbonInterface $start, CarbonInterface $end, int $limit = 10, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey($site->id, "top_referrers_{$limit}", $start, $end, $filters);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $limit, $filters) {
            $totalPageviews = $this->getPageviews($site, $start, $end, $filters);

            // Referrer platform names are normalized in PHP (ReferrerHelper), so
            // multiple raw referrer URLs can collapse into one platform. Fetch a
            // bounded superset at the database level (the schema has no
            // denormalized referrer_name column) and apply the real limit after
            // grouping, so memory stays bounded.
            $results = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->whereNotNull('referrer')
                ->where('referrer', '!=', '')
                ->select('referrer', DB::raw('count(*) as count'))
                ->groupBy('referrer')
                ->orderByDesc('count')
                ->limit($limit * 4)
                ->get();

            $grouped = $results->groupBy(function ($row) {
                return ReferrerHelper::parseName($row->referrer);
            });

            return $grouped
                ->map(function ($group, $platform) use ($totalPageviews) {
                    $count = $group->sum('count');

                    return [
                        'referrer' => (string) $platform,
                        'count' => $count,
                        'percentage' => $totalPageviews > 0 ? round(($count / $totalPageviews) * 100, 1) : 0.0,
                    ];
                })
                ->sort(function ($a, $b) {
                    if ($a['count'] === $b['count']) {
                        return strcmp($a['referrer'], $b['referrer']);
                    }

                    return $b['count'] <=> $a['count'];
                })
                ->take($limit)
                ->values()
                ->toArray();
        });

        return collect($data ?? []);
    }

    /**
     * Get pageview timeseries for site and date range (hourly if range <= 2 days, daily otherwise).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getDailyPageviews(Site $site, CarbonInterface $start, CarbonInterface $end, array $filters = []): Collection
    {
        $isHourly = $start->diffInHours($end) <= 48;
        $cacheKey = $this->cacheKey($site->id, $isHourly ? 'hourly_pageviews' : 'daily_pageviews', $start, $end, $filters);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $filters, $isHourly) {
            $isSqlite = DB::getDriverName() === 'sqlite';

            if ($isHourly) {
                $dateExpr = $isSqlite
                    ? DB::raw("strftime('%Y-%m-%d %H:00', created_at) as date")
                    : DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as date");
            } else {
                $dateExpr = DB::raw($this->dateExpression().' as date');
            }

            $results = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->select(
                    $dateExpr,
                    DB::raw('count(*) as pageviews'),
                    DB::raw('count(distinct '.$this->visitorExpression().') as visitors')
                )
                ->groupBy('date')
                ->get()
                ->keyBy('date');

            $series = [];

            if ($isHourly) {
                $curr = $start->copy()->startOfHour();
                $last = $end->copy()->startOfHour();

                while ($curr->lte($last)) {
                    $dateStr = $curr->format('Y-m-d H:00');
                    $row = $results->get($dateStr);

                    $series[] = [
                        'date' => $dateStr,
                        'pageviews' => $row ? (int) $row->pageviews : 0,
                        'visitors' => $row ? (int) $row->visitors : 0,
                    ];
                    $curr = $curr->addHour();
                }
            } else {
                $curr = $start->copy()->startOfDay();
                $last = $end->copy()->startOfDay();

                while ($curr->lte($last)) {
                    $dateStr = $curr->format('Y-m-d');
                    $row = $results->get($dateStr);

                    $series[] = [
                        'date' => $dateStr,
                        'pageviews' => $row ? (int) $row->pageviews : 0,
                        'visitors' => $row ? (int) $row->visitors : 0,
                    ];
                    $curr = $curr->addDay();
                }
            }

            return $series;
        });

        return collect($data ?? []);
    }

    /**
     * Get device breakdown (desktop, mobile, tablet, etc.).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getDeviceBreakdown(Site $site, CarbonInterface $start, CarbonInterface $end, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey($site->id, 'device_breakdown', $start, $end, $filters);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $filters) {
            $totalPageviews = $this->getPageviews($site, $start, $end, $filters);

            $results = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->select('device_type', DB::raw('count(*) as count'))
                ->groupBy('device_type')
                ->orderByDesc('count')
                ->get();

            return $results->map(function ($row) use ($totalPageviews) {
                $count = (int) $row->count;

                return [
                    'device' => is_object($row->device_type) ? $row->device_type->value : (string) $row->device_type,
                    'count' => $count,
                    'percentage' => $totalPageviews > 0 ? round(($count / $totalPageviews) * 100, 1) : 0.0,
                ];
            })->toArray();
        });

        return collect($data ?? []);
    }

    /**
     * Get top browsers for site and date range.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getTopBrowsers(Site $site, CarbonInterface $start, CarbonInterface $end, int $limit = 10, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey($site->id, "top_browsers_{$limit}", $start, $end, $filters);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $limit, $filters) {
            $totalPageviews = $this->getPageviews($site, $start, $end, $filters);

            $results = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->whereNotNull('browser')
                ->where('browser', '!=', '')
                ->select('browser', DB::raw('count(*) as count'))
                ->groupBy('browser')
                ->orderByDesc('count')
                ->orderBy('browser')
                ->limit($limit)
                ->get();

            return $results->map(function ($row) use ($totalPageviews) {
                $count = (int) $row->count;

                return [
                    'browser' => (string) $row->browser,
                    'count' => $count,
                    'percentage' => $totalPageviews > 0 ? round(($count / $totalPageviews) * 100, 1) : 0.0,
                ];
            })->toArray();
        });

        return collect($data ?? []);
    }

    /**
     * Get top operating systems for site and date range.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getTopOperatingSystems(Site $site, CarbonInterface $start, CarbonInterface $end, int $limit = 10, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey($site->id, "top_os_{$limit}", $start, $end, $filters);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $limit, $filters) {
            $totalPageviews = $this->getPageviews($site, $start, $end, $filters);

            $results = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->whereNotNull('os')
                ->where('os', '!=', '')
                ->select('os', DB::raw('count(*) as count'))
                ->groupBy('os')
                ->orderByDesc('count')
                ->orderBy('os')
                ->limit($limit)
                ->get();

            return $results->map(function ($row) use ($totalPageviews) {
                $count = (int) $row->count;

                return [
                    'os' => (string) $row->os,
                    'count' => $count,
                    'percentage' => $totalPageviews > 0 ? round(($count / $totalPageviews) * 100, 1) : 0.0,
                ];
            })->toArray();
        });

        return collect($data ?? []);
    }

    /**
     * Get top countries for site and date range.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getTopCountries(Site $site, CarbonInterface $start, CarbonInterface $end, int $limit = 10, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey($site->id, "top_countries_{$limit}", $start, $end, $filters);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $limit, $filters) {
            $totalPageviews = $this->getPageviews($site, $start, $end, $filters);

            $countryExpr = DB::raw('UPPER(TRIM(COALESCE(country_code, country))) as code');

            $results = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->where(function ($q) {
                    $q->whereNotNull('country_code')->orWhereNotNull('country');
                })
                ->select($countryExpr, DB::raw('MAX(country_name) as country_name'), DB::raw('count(*) as count'))
                ->groupBy('code')
                ->orderByDesc('count')
                ->orderBy('code')
                ->limit($limit)
                ->get();

            return $results
                ->map(function ($row) use ($totalPageviews) {
                    $code = (string) $row->code;
                    $name = $row->country_name ?: CountryHelper::getName($code);
                    if ($name === $code || empty($name)) {
                        $name = CountryHelper::getName($code) ?? $code;
                    }

                    $count = (int) $row->count;

                    return [
                        'code' => $code,
                        'name' => (string) $name,
                        'count' => $count,
                        'percentage' => $totalPageviews > 0 ? round(($count / $totalPageviews) * 100, 1) : 0.0,
                    ];
                })
                ->sort(function ($a, $b) {
                    if ($a['count'] === $b['count']) {
                        return strcmp($a['name'], $b['name']);
                    }

                    return $b['count'] <=> $a['count'];
                })
                ->take($limit)
                ->values()
                ->toArray();
        });

        return collect($data ?? []);
    }

    /**
     * Get custom event breakdown from metadata column.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getCustomEvents(Site $site, CarbonInterface $start, CarbonInterface $end, int $limit = 10, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey($site->id, "custom_events_{$limit}", $start, $end, $filters);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $limit, $filters) {
            $nameExpr = $this->eventNameExpression();

            $results = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->whereNotNull('metadata')
                ->whereRaw($nameExpr.' IS NOT NULL')
                ->selectRaw("{$nameExpr} as event_name, count(*) as count")
                ->groupBy('event_name')
                ->orderByDesc('count')
                ->orderBy('event_name')
                ->limit($limit)
                ->get();

            return $results->map(fn ($row) => [
                'name' => (string) $row->event_name,
                'count' => (int) $row->count,
            ])->toArray();
        });

        return collect($data ?? []);
    }

    /**
     * Get currently active visitors in the last N minutes.
     */
    public function getCurrentVisitors(Site $site, int $minutes = 5): int
    {
        $cacheKey = "lumina:analytics:{$site->id}:current_visitors_{$minutes}m";

        return (int) $this->rememberCache($site->id, $cacheKey, function () use ($site, $minutes) {
            return Event::where('site_id', $site->id)
                ->where('created_at', '>=', now()->subMinutes($minutes))
                ->distinct()
                ->count(DB::raw($this->visitorExpression()));
        }, 15);
    }

    /**
     * Get bounce rate — percentage of sessions with exactly one pageview.
     *
     * Session-based via `session_id`, with a documented visitor-level fallback
     * for legacy rows that predate the session column
     * (`COALESCE(session_id, visitor_hash)`). Aggregated entirely in SQL.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getBounceRate(Site $site, CarbonInterface $start, CarbonInterface $end, array $filters = []): float
    {
        $cacheKey = $this->cacheKey($site->id, 'bounce_rate', $start, $end, $filters);

        return (float) $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $filters) {
            $sessionExpr = $this->sessionExpression();

            $baseQuery = fn () => Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters));

            $totalSessions = (int) $baseQuery()
                ->distinct()
                ->count(DB::raw($sessionExpr));

            if ($totalSessions === 0) {
                return 0.0;
            }

            $inner = $baseQuery()
                ->select(DB::raw($sessionExpr.' as session_key'))
                ->groupBy('session_key')
                ->havingRaw('COUNT(*) = 1');

            $bounces = (int) DB::query()->from($inner, 'bounces')->count();

            return round(($bounces / $totalSessions) * 100, 1);
        });
    }

    /**
     * Get average session duration in seconds across sessions with more than
     * one pageview in the range (MAX(created_at) − MIN(created_at) per
     * session). Session-based via `session_id`, falling back to the visitor
     * key for legacy rows. Aggregated entirely in SQL.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getAvgVisitDuration(Site $site, CarbonInterface $start, CarbonInterface $end, array $filters = []): int
    {
        $cacheKey = $this->cacheKey($site->id, 'avg_visit_duration', $start, $end, $filters);

        return (int) $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $filters) {
            $sessionExpr = $this->sessionExpression();

            $inner = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->select(DB::raw($sessionExpr.' as session_key'))
                ->selectRaw('MIN(created_at) as first_seen, MAX(created_at) as last_seen')
                ->groupBy('session_key')
                ->havingRaw('MIN(created_at) <> MAX(created_at)');

            $durationExpr = DB::getDriverName() === 'sqlite'
                ? 'AVG((julianday(last_seen) - julianday(first_seen)) * 86400)'
                : 'AVG(TIMESTAMPDIFF(SECOND, first_seen, last_seen))';

            $avgDuration = DB::query()
                ->from($inner, 'durations')
                ->selectRaw("{$durationExpr} as avg_duration")
                ->value('avg_duration');

            return $avgDuration ? (int) round((float) $avgDuration) : 0;
        });
    }

    /**
     * Get UTM campaign breakdown for site and date range.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getUtmCampaigns(Site $site, CarbonInterface $start, CarbonInterface $end, int $limit = 10, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey($site->id, "utm_campaigns_{$limit}", $start, $end, $filters);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $limit, $filters) {
            $totalPageviews = $this->getPageviews($site, $start, $end, $filters);

            $results = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->whereNotNull('utm_campaign')
                ->where('utm_campaign', '!=', '')
                ->select('utm_campaign', 'utm_source', 'utm_medium', DB::raw('count(*) as count'))
                ->groupBy('utm_campaign', 'utm_source', 'utm_medium')
                ->orderByDesc('count')
                ->limit($limit)
                ->get();

            return $results->map(function ($row) use ($totalPageviews) {
                $count = (int) $row->count;

                return [
                    'campaign' => (string) $row->utm_campaign,
                    'source' => $row->utm_source ? (string) $row->utm_source : null,
                    'medium' => $row->utm_medium ? (string) $row->utm_medium : null,
                    'count' => $count,
                    'percentage' => $totalPageviews > 0 ? round(($count / $totalPageviews) * 100, 1) : 0.0,
                ];
            })->toArray();
        });

        return collect($data ?? []);
    }

    /**
     * Get fast KPI metrics only (pageviews, visitors, current, bounce, duration, daily chart).
     * Intentionally excludes breakdown cards — those are deferred separately.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getKpis(Site $site, CarbonInterface $start, CarbonInterface $end, array $filters = []): array
    {
        return [
            'total_pageviews' => $this->getPageviews($site, $start, $end, $filters),
            'unique_visitors' => $this->getUniqueVisitors($site, $start, $end, $filters),
            'current_visitors' => $this->getCurrentVisitors($site),
            'bounce_rate' => $this->getBounceRate($site, $start, $end, $filters),
            'avg_duration' => $this->getAvgVisitDuration($site, $start, $end, $filters),
            'daily_pageviews' => $this->getDailyPageviews($site, $start, $end, $filters),
        ];
    }

    /**
     * Get complete dashboard overview payload.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getOverview(Site $site, CarbonInterface $start, CarbonInterface $end, array $filters = []): array
    {
        return [
            'total_pageviews' => $this->getPageviews($site, $start, $end, $filters),
            'unique_visitors' => $this->getUniqueVisitors($site, $start, $end, $filters),
            'current_visitors' => $this->getCurrentVisitors($site),
            'bounce_rate' => $this->getBounceRate($site, $start, $end, $filters),
            'avg_duration' => $this->getAvgVisitDuration($site, $start, $end, $filters),
            'top_pages' => $this->getTopPages($site, $start, $end, 50, $filters),
            'top_referrers' => $this->getTopReferrers($site, $start, $end, 50, $filters),
            'daily_pageviews' => $this->getDailyPageviews($site, $start, $end, $filters),
            'device_breakdown' => $this->getDeviceBreakdown($site, $start, $end, $filters),
            'top_browsers' => $this->getTopBrowsers($site, $start, $end, 50, $filters),
            'top_os' => $this->getTopOperatingSystems($site, $start, $end, 50, $filters),
            'top_countries' => $this->getTopCountries($site, $start, $end, 50, $filters),
            'utm_campaigns' => $this->getUtmCampaigns($site, $start, $end, 50, $filters),
            'custom_events' => $this->getCustomEvents($site, $start, $end, 50, $filters),
            'goals' => $this->getGoals($site, $start, $end, $filters),
        ];
    }

    /**
     * Generate deterministic cache key.
     *
     * Full timestamps (not just dates) are included so ranges that share the
     * same day but differ in time-of-day can never collide. Filters are
     * canonicalized by sorting keys, and extra parameters are sorted, so
     * semantically identical calls always map to one key.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, mixed>  $extra
     */
    protected function cacheKey(int $siteId, string $metric, CarbonInterface $start, CarbonInterface $end, array $filters = [], array $extra = []): string
    {
        $sStr = $start->format('Y-m-d H:i:s');
        $eStr = $end->format('Y-m-d H:i:s');

        $key = "lumina:analytics:{$siteId}:{$metric}:{$sStr}:{$eStr}";

        if (! empty($filters)) {
            ksort($filters);
            $key .= ':f_'.md5((string) json_encode($filters));
        }

        if (! empty($extra)) {
            sort($extra);
            $key .= ':'.implode(':', $extra);
        }

        return $key;
    }

    /**
     * Get summary KPIs for custom events.
     *
     * @return array<string, mixed>
     */
    public function getCustomEventSummary(Site $site, CarbonInterface $start, CarbonInterface $end, ?string $selectedEvent = null): array
    {
        $cacheKey = $this->cacheKey($site->id, 'custom_event_summary', $start, $end, [], [$selectedEvent ?? 'all']);

        return $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $selectedEvent) {
            $nameExpr = $this->eventNameExpression();

            $query = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->whereNotNull('metadata')
                ->whereRaw($nameExpr.' IS NOT NULL');

            if ($selectedEvent) {
                $query->whereRaw($nameExpr.' = ?', [$selectedEvent]);
            }

            $totalEvents = (clone $query)->count();
            $uniqueEventNames = (clone $query)->distinct()->count(DB::raw($nameExpr));

            $topEvent = (clone $query)
                ->selectRaw("{$nameExpr} as name, count(*) as count")
                ->groupBy('name')
                ->orderByDesc('count')
                ->orderBy('name')
                ->first();

            return [
                'total_custom_events' => $totalEvents,
                'unique_event_names' => $uniqueEventNames,
                'top_event_name' => $topEvent ? (string) $topEvent->name : null,
            ];
        }, $this->shortTtl);
    }

    /**
     * Get list of all distinct custom event names with counts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getCustomEventsList(Site $site, CarbonInterface $start, CarbonInterface $end): Collection
    {
        $cacheKey = $this->cacheKey($site->id, 'custom_events_list', $start, $end);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end) {
            $nameExpr = $this->eventNameExpression();

            $results = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->whereNotNull('metadata')
                ->whereRaw($nameExpr.' IS NOT NULL')
                ->selectRaw("{$nameExpr} as name, count(*) as count, MAX(created_at) as last_seen")
                ->groupBy('name')
                ->orderByDesc('count')
                ->orderBy('name')
                ->get();

            $totalEvents = (int) $results->sum('count');

            return $results->map(function ($row) use ($totalEvents) {
                $count = (int) $row->count;

                return [
                    'name' => (string) $row->name,
                    'count' => $count,
                    'percentage' => $totalEvents > 0 ? round(($count / $totalEvents) * 100, 1) : 0.0,
                    'last_seen' => Carbon::parse($row->last_seen)->toDateTimeString(),
                ];
            })->toArray();
        });

        return collect($data ?? []);
    }

    /**
     * Get daily timeseries for custom events.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getCustomEventTimeline(Site $site, CarbonInterface $start, CarbonInterface $end, ?string $eventName = null): Collection
    {
        $cacheKey = $this->cacheKey($site->id, 'custom_event_timeline', $start, $end, [], [$eventName ?? 'all']);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $eventName) {
            $nameExpr = $this->eventNameExpression();

            $query = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->whereNotNull('metadata')
                ->whereRaw($nameExpr.' IS NOT NULL');

            if ($eventName) {
                $query->whereRaw($nameExpr.' = ?', [$eventName]);
            }

            $results = $query
                ->selectRaw($this->dateExpression().' as date, count(*) as count')
                ->groupBy('date')
                ->get()
                ->keyBy('date');

            $series = [];
            $curr = $start->copy()->startOfDay();
            $last = $end->copy()->startOfDay();

            while ($curr->lte($last)) {
                $dateStr = $curr->format('Y-m-d');
                $row = $results->get($dateStr);

                $series[] = [
                    'date' => $dateStr,
                    'count' => $row ? (int) $row->count : 0,
                ];
                $curr = $curr->addDay();
            }

            return $series;
        }, $this->shortTtl);

        return collect($data ?? []);
    }

    /**
     * Get property keys for a custom event.
     *
     * @return array<int, string>
     */
    public function getCustomEventPropertyKeys(Site $site, string $eventName, CarbonInterface $start, CarbonInterface $end): array
    {
        $cacheKey = $this->cacheKey($site->id, 'custom_event_property_keys', $start, $end, [], [$eventName]);

        return $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $eventName) {
            if (DB::getDriverName() === 'sqlite') {
                $rows = DB::select(
                    "SELECT DISTINCT je.key AS prop_key
                     FROM events e, json_each(e.metadata, '$.props') je
                     WHERE e.site_id = ? AND e.created_at BETWEEN ? AND ?
                       AND json_extract(e.metadata, '$.name') = ?
                       AND je.key IS NOT NULL
                     ORDER BY prop_key",
                    [$site->id, $start->toDateTimeString(), $end->toDateTimeString(), $eventName]
                );
            } else {
                $rows = DB::select(
                    "SELECT DISTINCT jt.prop_key
                     FROM events e,
                     JSON_TABLE(JSON_KEYS(JSON_EXTRACT(e.metadata, '$.props')), '$[*]' COLUMNS (prop_key VARCHAR(255) PATH '$')) jt
                     WHERE e.site_id = ? AND e.created_at BETWEEN ? AND ?
                       AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata, '$.name')) = ?
                     ORDER BY prop_key",
                    [$site->id, $start->toDateTimeString(), $end->toDateTimeString(), $eventName]
                );
            }

            return collect($rows)->map(fn ($row) => (string) $row->prop_key)->values()->all();
        }, $this->shortTtl);
    }

    /**
     * Get property value breakdown.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getCustomEventPropertyBreakdown(Site $site, string $eventName, string $propertyKey, CarbonInterface $start, CarbonInterface $end, int $limit = 10): Collection
    {
        $cacheKey = $this->cacheKey($site->id, 'custom_event_property_breakdown', $start, $end, [], [$eventName, $propertyKey, $limit]);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $eventName, $propertyKey, $limit) {

            // json_encode() yields a quoted JSON string (e.g. "\"plan\""), which
            // is the correct JSON-path component syntax on both drivers and
            // cannot break out of the path (injection-safe for user-supplied
            // property keys).
            $path = json_encode((string) $propertyKey);

            $query = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->whereNotNull('metadata')
                ->whereRaw($this->eventNameExpression().' = ?', [$eventName]);

            if (DB::getDriverName() === 'sqlite') {
                $query->whereRaw("json_type(metadata, '$.props.' || ?) IS NOT NULL", [$path]);
                $valueExpr = "COALESCE(json_extract(metadata, '$.props.' || ?), '')";
            } else {
                $query->whereRaw("JSON_EXTRACT(metadata, CONCAT('$.props.', ?)) IS NOT NULL", [$path]);
                $valueExpr = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, CONCAT('$.props.', ?))), '')";
            }

            $results = (clone $query)
                ->selectRaw("{$valueExpr} as value, count(*) as count", [$path])
                ->groupBy('value')
                ->orderByDesc('count')
                ->orderBy('value')
                ->limit($limit)
                ->get();

            $total = (int) $results->sum('count');

            return $results->map(function ($row) use ($total) {
                $count = (int) $row->count;

                return [
                    'value' => $row->value === '' ? '(empty)' : (string) $row->value,
                    'count' => $count,
                    'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                ];
            })->toArray();
        }, $this->shortTtl);

        return collect($data ?? []);
    }

    /**
     * Get custom event logs.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getCustomEventLogs(Site $site, CarbonInterface $start, CarbonInterface $end, ?string $eventName = null, int $limit = 50): Collection
    {
        $cacheKey = $this->cacheKey($site->id, 'custom_event_logs', $start, $end, [], [$eventName ?? 'all', $limit]);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $eventName, $limit) {

            $nameExpr = $this->eventNameExpression();

            $query = Event::where('site_id', $site->id)
                ->whereBetween('created_at', [$start, $end])
                ->whereNotNull('metadata')
                ->whereRaw($nameExpr.' IS NOT NULL')
                ->latest();

            if ($eventName) {
                $query->whereRaw($nameExpr.' = ?', [$eventName]);
            }

            return $query->limit($limit)->get()->map(function ($e) {
                $props = $e->metadata['props'] ?? null;

                return [
                    'id' => $e->id,
                    'created_at' => $e->created_at->toDateTimeString(),
                    'path' => $e->path,
                    'visitor_hash' => $e->visitor_hash,
                    'visitor_id' => $e->visitor_id ?? $e->visitor_hash,
                    'device_type' => is_object($e->device_type) ? $e->device_type->value : (string) $e->device_type,
                    'browser' => $e->browser,
                    'os' => $e->os,
                    'country_name' => $e->country_name ?? $e->country,
                    'country_code' => $e->country_code,
                    'event_name' => $e->metadata['name'],
                    'props' => $props,
                ];
            })->toArray();
        }, $this->shortTtl);

        return collect($data ?? []);
    }

    /**
     * Get goals and conversion rates.
     *
     * `completions` counts raw matching events (GA4-style). `conversion_rate`
     * is unique converters / unique visitors, with both sides scoped to the
     * active filters — repeated completions by the same visitor never inflate
     * conversion. Path goals match on the same `clean_path` semantics used by
     * page analytics.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getGoals(Site $site, CarbonInterface $start, CarbonInterface $end, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey($site->id, 'goals', $start, $end, $filters);

        /** @var array<int, array<string, mixed>>|null $data */
        $data = $this->rememberCache($site->id, $cacheKey, function () use ($site, $start, $end, $filters) {
            // Fresh-load instead of using $site->goals so a previously resolved
            // (possibly stale) relation on the model instance is never reused.
            $goals = $site->goals()->get();

            if ($goals->isEmpty()) {
                return [];
            }

            $uniqueVisitors = $this->getUniqueVisitors($site, $start, $end, $filters);
            $nameExpr = $this->eventNameExpression();
            $dateExpr = $this->dateExpression();
            $results = [];

            foreach ($goals as $goal) {
                $query = Event::where('site_id', $site->id)
                    ->whereBetween('created_at', [$start, $end])
                    ->tap(fn ($q) => $this->applyFilters($q, $filters));

                if ($goal->target_type === 'path') {
                    $pathExpr = $this->pathExpression();

                    if (str_contains($goal->target_value, '*')) {
                        $pattern = str_replace('*', '%', $goal->target_value);
                        $query->whereRaw($pathExpr.' LIKE ?', [$pattern]);
                    } else {
                        $query->whereRaw($pathExpr.' = ?', [$goal->target_value]);
                    }
                } elseif ($goal->target_type === 'custom_event') {
                    $query->whereNotNull('metadata')
                        ->whereRaw($nameExpr.' = ?', [$goal->target_value]);
                } else {
                    $results[] = [
                        'id' => $goal->id,
                        'name' => $goal->name,
                        'target_type' => $goal->target_type,
                        'target_value' => $goal->target_value,
                        'completions' => 0,
                        'conversion_rate' => 0.0,
                        'trend' => $this->emptyTrend($start, $end),
                    ];

                    continue;
                }

                $visitorExpr = $this->visitorExpression();

                $goalRows = (clone $query)
                    ->selectRaw("{$dateExpr} as date, {$visitorExpr} as visitor_key, count(*) as completions")
                    ->groupBy('date', DB::raw($visitorExpr))
                    ->get();

                $converters = $goalRows->pluck('visitor_key')->filter(fn ($v) => ! is_null($v) && $v !== '')->unique()->count();
                $conversionRate = $uniqueVisitors > 0 ? round(($converters / $uniqueVisitors) * 100, 1) : 0.0;

                $trendRows = $goalRows->groupBy('date')->map(fn ($group) => (int) $group->sum('completions'));
                $completions = (int) $goalRows->sum('completions');

                $trend = [];
                $curr = $start->copy()->startOfDay();
                $last = $end->copy()->startOfDay();

                while ($curr->lte($last)) {
                    $dateStr = $curr->format('Y-m-d');
                    $trend[] = [
                        'date' => $dateStr,
                        'completions' => $trendRows->get($dateStr, 0),
                    ];
                    $curr = $curr->addDay();
                }

                $results[] = [
                    'id' => $goal->id,
                    'name' => $goal->name,
                    'target_type' => $goal->target_type,
                    'target_value' => $goal->target_value,
                    'completions' => $completions,
                    'conversion_rate' => $conversionRate,
                    'trend' => $trend,
                ];
            }

            return $results;
        });

        return collect($data ?? []);
    }

    /**
     * Build a zero-filled daily trend for the given inclusive range.
     *
     * @return array<int, array{date: string, completions: int}>
     */
    protected function emptyTrend(CarbonInterface $start, CarbonInterface $end): array
    {
        $trend = [];
        $curr = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();

        while ($curr->lte($last)) {
            $trend[] = [
                'date' => $curr->format('Y-m-d'),
                'completions' => 0,
            ];
            $curr = $curr->addDay();
        }

        return $trend;
    }

    /**
     * Cache helper with tag support if available.
     *
     * When the driver supports tags the entry is tagged with
     * `lumina:site:{id}` so clearCache() can invalidate it. On non-tag drivers
     * `$nonTagTtl` may be used to bound staleness for keys the clearCache()
     * fallback loop cannot enumerate (see `$shortTtl`).
     *
     * @param  Closure(): mixed  $callback
     */
    protected function rememberCache(int $siteId, string $key, Closure $callback, ?int $nonTagTtl = null): mixed
    {
        if (Cache::supportsTags()) {
            return Cache::tags(["lumina:site:{$siteId}"])->remember($key, $this->ttl, $callback);
        }

        return Cache::remember($key, $nonTagTtl ?? $this->ttl, $callback);
    }
}
