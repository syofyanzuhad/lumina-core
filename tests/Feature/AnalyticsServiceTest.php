<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lumina\Core\Enums\DeviceType;
use Lumina\Core\Models\Event;
use Lumina\Core\Models\Goal;
use Lumina\Core\Models\Site;
use Lumina\Core\Services\AnalyticsService;
use Lumina\Core\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Rebuild daily_visitor_stats from the events table. Production ingestion
 * (InsertEvent) maintains this table on every event; tests create events
 * directly, so the same state must be reproduced for the unfiltered
 * unique-visitor and bounce-rate paths, which read from it.
 */
function backfillDailyVisitorStats(): void
{
    // Idempotent: the helper may be called more than once per test (e.g. after
    // seeding additional events), so rebuild from scratch instead of appending.
    DB::table('daily_visitor_stats')->delete();

    $rows = DB::table('events')
        ->selectRaw('site_id, DATE(created_at) as date, visitor_hash, COUNT(*) as views')
        ->groupBy('site_id', 'date', 'visitor_hash')
        ->get();

    foreach ($rows as $row) {
        DB::table('daily_visitor_stats')->insert([
            'site_id' => $row->site_id,
            'date' => $row->date,
            'visitor_hash' => $row->visitor_hash,
            'views' => $row->views,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

beforeEach(function () {
    // Array store supports cache tags, which makes clearCache() exact and keeps
    // the suite hermetic (no shared cache table across tests).
    config(['cache.default' => 'array']);

    $this->service = new AnalyticsService;

    $this->siteA = Site::factory()->create(['domain' => 'site-a.com']);
    $this->siteB = Site::factory()->create(['domain' => 'site-b.com']);

    $this->startDate = Carbon::parse('2026-07-01 00:00:00');
    $this->endDate = Carbon::parse('2026-07-03 23:59:59');

    // Seed events for Site A
    // Day 1: 3 events (2 unique visitors, 2 for /home, 1 for /pricing)
    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/home',
        'referrer' => 'https://google.com',
        'visitor_hash' => 'hash_visitor_1',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-01 10:00:00'),
    ]);
    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/home',
        'referrer' => 'https://google.com',
        'visitor_hash' => 'hash_visitor_1',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-01 11:00:00'),
    ]);
    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/pricing',
        'referrer' => 'https://twitter.com',
        'visitor_hash' => 'hash_visitor_2',
        'device_type' => DeviceType::Mobile,
        'created_at' => Carbon::parse('2026-07-01 12:00:00'),
    ]);

    // Day 2: 2 events for Site A (1 custom event)
    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/checkout',
        'referrer' => 'https://google.com',
        'visitor_hash' => 'hash_visitor_3',
        'device_type' => DeviceType::Desktop,
        'metadata' => ['name' => 'purchase_click', 'props' => ['plan' => 'pro']],
        'created_at' => Carbon::parse('2026-07-02 14:00:00'),
    ]);
    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/checkout',
        'referrer' => null,
        'visitor_hash' => 'hash_visitor_3',
        'device_type' => DeviceType::Desktop,
        'metadata' => ['name' => 'purchase_click', 'props' => ['plan' => 'enterprise']],
        'created_at' => Carbon::parse('2026-07-02 15:00:00'),
    ]);

    // Out-of-bounds event for Site A (July 10)
    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/out-of-bounds',
        'referrer' => null,
        'visitor_hash' => 'hash_visitor_99',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-10 10:00:00'),
    ]);

    // Event for Site B (should be ignored)
    Event::create([
        'site_id' => $this->siteB->id,
        'path' => '/other-site',
        'referrer' => null,
        'visitor_hash' => 'hash_visitor_site_b',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-01 10:00:00'),
    ]);

    backfillDailyVisitorStats();
});

test('it calculates total pageviews correctly for site and date range', function () {
    $pageviews = $this->service->getPageviews($this->siteA, $this->startDate, $this->endDate);

    expect($pageviews)->toBe(5);
});

test('it calculates unique visitors correctly', function () {
    $visitors = $this->service->getUniqueVisitors($this->siteA, $this->startDate, $this->endDate);

    // hash_visitor_1, hash_visitor_2, hash_visitor_3 = 3 unique visitors
    expect($visitors)->toBe(3);
});

test('it calculates top pages with count and percentage', function () {
    $topPages = $this->service->getTopPages($this->siteA, $this->startDate, $this->endDate);

    expect($topPages)->toHaveCount(3);
    expect($topPages->first())->toBe([
        'path' => '/checkout',
        'count' => 2,
        'percentage' => 40.0,
    ]);
});

test('it calculates top referrers with count and percentage', function () {
    $topReferrers = $this->service->getTopReferrers($this->siteA, $this->startDate, $this->endDate);

    expect($topReferrers)->toHaveCount(2);
    expect($topReferrers->first())->toBe([
        'referrer' => 'Google',
        'count' => 3,
        'percentage' => 60.0,
    ]);
});

test('it generates daily pageview timeseries for date range', function () {
    $daily = $this->service->getDailyPageviews($this->siteA, $this->startDate, $this->endDate);

    expect($daily)->toHaveCount(3);
    expect($daily[0])->toBe([
        'date' => '2026-07-01',
        'pageviews' => 3,
        'visitors' => 2,
    ]);
    expect($daily[1])->toBe([
        'date' => '2026-07-02',
        'pageviews' => 2,
        'visitors' => 1,
    ]);
    expect($daily[2])->toBe([
        'date' => '2026-07-03',
        'pageviews' => 0,
        'visitors' => 0,
    ]);
});

test('it aggregates custom events from metadata column', function () {
    $customEvents = $this->service->getCustomEvents($this->siteA, $this->startDate, $this->endDate);

    expect($customEvents)->toHaveCount(1);
    expect($customEvents->first())->toBe([
        'name' => 'purchase_click',
        'count' => 2,
    ]);
});

test('it returns complete dashboard overview payload', function () {
    $overview = $this->service->getOverview($this->siteA, $this->startDate, $this->endDate);

    expect($overview)->toHaveKeys([
        'total_pageviews',
        'unique_visitors',
        'top_pages',
        'top_referrers',
        'daily_pageviews',
        'custom_events',
        'goals',
    ]);
    expect($overview['total_pageviews'])->toBe(5);
    expect($overview['unique_visitors'])->toBe(3);
});

test('it computes goal metrics with unique-converter conversion rate', function () {
    Goal::create([
        'site_id' => $this->siteA->id,
        'name' => 'Viewed Pricing',
        'target_type' => 'path',
        'target_value' => '/pricing',
    ]);

    Goal::create([
        'site_id' => $this->siteA->id,
        'name' => 'Purchased',
        'target_type' => 'custom_event',
        'target_value' => 'purchase_click',
    ]);

    $goals = $this->service->getGoals($this->siteA, $this->startDate, $this->endDate);

    expect($goals)->toHaveCount(2);

    // First goal: /pricing — 1 completion by 1 converter out of 3 visitors
    expect($goals[0]['name'])->toBe('Viewed Pricing');
    expect($goals[0]['completions'])->toBe(1);
    expect($goals[0]['conversion_rate'])->toBe(33.3);

    // Second goal: purchase_click — 2 raw events, but only 1 unique converter
    // (hash_visitor_3 completed twice), so conversion is 33.3, not 66.7.
    expect($goals[1]['name'])->toBe('Purchased');
    expect($goals[1]['completions'])->toBe(2);
    expect($goals[1]['conversion_rate'])->toBe(33.3);

    // Check trend for second goal (raw completions per day)
    expect($goals[1]['trend'])->toHaveCount(3);
    expect($goals[1]['trend'][1])->toBe([
        'date' => '2026-07-02',
        'completions' => 2,
    ]);
});

test('it caches aggregation queries for 60 seconds', function () {
    // First call populates cache
    $firstCall = $this->service->getPageviews($this->siteA, $this->startDate, $this->endDate);
    expect($firstCall)->toBe(5);

    // Add extra event directly to database
    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/new-event',
        'referrer' => null,
        'visitor_hash' => 'hash_new',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-01 15:00:00'),
    ]);

    // Second call reads cached value (still 5)
    $secondCall = $this->service->getPageviews($this->siteA, $this->startDate, $this->endDate);
    expect($secondCall)->toBe(5);

    // Clearing cache retrieves fresh count (6)
    Cache::flush();
    $thirdCall = $this->service->getPageviews($this->siteA, $this->startDate, $this->endDate);
    expect($thirdCall)->toBe(6);
});

test('it aggregates custom event summary', function () {
    $summary = $this->service->getCustomEventSummary($this->siteA, $this->startDate, $this->endDate);

    expect($summary)->toBe([
        'total_custom_events' => 2,
        'unique_event_names' => 1,
        'top_event_name' => 'purchase_click',
    ]);
});

test('it aggregates custom event list with counts and percentages', function () {
    $list = $this->service->getCustomEventsList($this->siteA, $this->startDate, $this->endDate);

    expect($list)->toHaveCount(1);
    expect($list->first())->toHaveKeys(['name', 'count', 'percentage', 'last_seen']);
    expect($list->first()['name'])->toBe('purchase_click');
    expect($list->first()['count'])->toBe(2);
    expect($list->first()['percentage'])->toBe(100.0);
});

test('it generates daily timeline timeseries for custom events', function () {
    $timelineAll = $this->service->getCustomEventTimeline($this->siteA, $this->startDate, $this->endDate);

    expect($timelineAll)->toHaveCount(3);
    expect($timelineAll[1])->toBe([
        'date' => '2026-07-02',
        'count' => 2,
    ]);

    $timelineFiltered = $this->service->getCustomEventTimeline($this->siteA, $this->startDate, $this->endDate, 'purchase_click');
    expect($timelineFiltered[1]['count'])->toBe(2);

    $timelineFilteredEmpty = $this->service->getCustomEventTimeline($this->siteA, $this->startDate, $this->endDate, 'non_existent');
    expect($timelineFilteredEmpty[1]['count'])->toBe(0);
});

test('it extracts distinct metadata property keys for a given event name', function () {
    $keys = $this->service->getCustomEventPropertyKeys($this->siteA, 'purchase_click', $this->startDate, $this->endDate);

    expect($keys)->toBe(['plan']);
});

test('it calculates property value distributions for a specified property key', function () {
    $breakdown = $this->service->getCustomEventPropertyBreakdown($this->siteA, 'purchase_click', 'plan', $this->startDate, $this->endDate);

    expect($breakdown)->toHaveCount(2);
    // Ties are broken alphabetically for determinism.
    expect($breakdown[0])->toBe([
        'value' => 'enterprise',
        'count' => 1,
        'percentage' => 50.0,
    ]);
    expect($breakdown[1])->toBe([
        'value' => 'pro',
        'count' => 1,
        'percentage' => 50.0,
    ]);
});

test('it fetches recent custom event log records with formatted metadata payload', function () {
    $logs = $this->service->getCustomEventLogs($this->siteA, $this->startDate, $this->endDate);

    expect($logs)->toHaveCount(2);
    expect($logs->first())->toHaveKeys(['id', 'created_at', 'path', 'visitor_hash', 'device_type', 'browser', 'os', 'country_name', 'country_code', 'event_name', 'props']);
    expect($logs->first()['event_name'])->toBe('purchase_click');
    expect($logs->first()['props'])->toBe(['plan' => 'enterprise']); // latest first
});

test('it calculates bounce rate as percentage of single-pageview visitors', function () {
    // hash_visitor_1 and hash_visitor_3 have 2 pageviews each; hash_visitor_2 has 1.
    $bounceRate = $this->service->getBounceRate($this->siteA, $this->startDate, $this->endDate);

    expect($bounceRate)->toBe(33.3);
});

test('it calculates average visit duration across multi-pageview visitors', function () {
    // hash_visitor_1: 10:00 -> 11:00 = 3600s; hash_visitor_3: 14:00 -> 15:00 = 3600s.
    $avgDuration = $this->service->getAvgVisitDuration($this->siteA, $this->startDate, $this->endDate);

    expect($avgDuration)->toBe(3600);
});

test('average visit duration respects filters without cache collisions', function () {
    // Unfiltered: visitors 1 and 3 span one hour each -> 3600.
    $unfiltered = $this->service->getAvgVisitDuration($this->siteA, $this->startDate, $this->endDate);
    expect($unfiltered)->toBe(3600);

    // Mobile only: hash_visitor_2 has a single pageview -> no multi-event visitors -> 0.
    $mobile = $this->service->getAvgVisitDuration($this->siteA, $this->startDate, $this->endDate, ['device' => 'mobile']);
    expect($mobile)->toBe(0);

    // Desktop: same multi-event visitors as unfiltered (v1, v3 are desktop) -> 3600.
    $desktop = $this->service->getAvgVisitDuration($this->siteA, $this->startDate, $this->endDate, ['device' => 'desktop']);
    expect($desktop)->toBe(3600);

    // Re-query unfiltered after the filtered calls: must still be 3600, proving
    // the filtered and unfiltered results did not collide in the cache.
    expect($this->service->getAvgVisitDuration($this->siteA, $this->startDate, $this->endDate))->toBe(3600);
});

test('it respects the device filter when computing bounce rate', function () {
    $desktop = $this->service->getBounceRate($this->siteA, $this->startDate, $this->endDate, ['device' => 'desktop']);
    expect($desktop)->toBe(0.0);

    $mobile = $this->service->getBounceRate($this->siteA, $this->startDate, $this->endDate, ['device' => 'mobile']);
    expect($mobile)->toBe(100.0);
});

test('goal conversion rate uses unique converters and respects filters', function () {
    Goal::create([
        'site_id' => $this->siteA->id,
        'name' => 'Home Views',
        'target_type' => 'path',
        'target_value' => '/home',
    ]);

    $unfiltered = $this->service->getGoals($this->siteA, $this->startDate, $this->endDate);
    // 2 raw completions, 1 converter (hash_visitor_1) out of 3 visitors.
    expect($unfiltered->first()['completions'])->toBe(2);
    expect($unfiltered->first()['conversion_rate'])->toBe(33.3);

    // Same goal, but now the denominator is scoped to the /home segment: only
    // hash_visitor_1 is eligible, so conversion is 100%.
    $filtered = $this->service->getGoals($this->siteA, $this->startDate, $this->endDate, ['path' => '/home']);
    expect($filtered->first()['completions'])->toBe(2);
    expect($filtered->first()['conversion_rate'])->toBe(100.0);
});

test('repeated goal completions by the same visitor do not inflate conversion rate', function () {
    Goal::create([
        'site_id' => $this->siteA->id,
        'name' => 'Purchased',
        'target_type' => 'custom_event',
        'target_value' => 'purchase_click',
    ]);

    $before = $this->service->getGoals($this->siteA, $this->startDate, $this->endDate)->first();
    expect($before['completions'])->toBe(2);
    expect($before['conversion_rate'])->toBe(33.3);

    // Same visitor completes the goal a third time: completions rise, conversion does not.
    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/checkout',
        'referrer' => null,
        'visitor_hash' => 'hash_visitor_3',
        'device_type' => DeviceType::Desktop,
        'metadata' => ['name' => 'purchase_click', 'props' => ['plan' => 'pro']],
        'created_at' => Carbon::parse('2026-07-02 16:00:00'),
    ]);
    $this->service->clearCache($this->siteA);

    $after = $this->service->getGoals($this->siteA, $this->startDate, $this->endDate)->first();
    expect($after['completions'])->toBe(3);
    expect($after['conversion_rate'])->toBe(33.3);
});

test('goal matching aligns with clean_path including legacy rows', function () {
    $site = Site::factory()->create(['domain' => 'clean-path.com']);
    $start = Carbon::parse('2026-07-01 00:00:00');
    $end = Carbon::parse('2026-07-02 23:59:59');

    Goal::create([
        'site_id' => $site->id,
        'name' => 'Pricing Visits',
        'target_type' => 'path',
        'target_value' => '/pricing',
    ]);

    // Newer row: clean_path populated at ingestion.
    Event::create([
        'site_id' => $site->id,
        'path' => '/pricing?utm_source=newsletter',
        'clean_path' => '/pricing',
        'visitor_hash' => 'hash_v1',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-01 10:00:00'),
    ]);

    // Legacy row: clean_path NULL — must still match via the COALESCE fallback.
    Event::create([
        'site_id' => $site->id,
        'path' => '/pricing?ref=twitter',
        'clean_path' => null,
        'visitor_hash' => 'hash_v2',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-01 11:00:00'),
    ]);

    // A path with the goal as a prefix must NOT match an exact-path goal.
    Event::create([
        'site_id' => $site->id,
        'path' => '/pricing/subpage',
        'clean_path' => '/pricing/subpage',
        'visitor_hash' => 'hash_v3',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-01 12:00:00'),
    ]);

    backfillDailyVisitorStats();

    $goals = $this->service->getGoals($site, $start, $end);

    expect($goals->first()['completions'])->toBe(2);
    // 2 converters (hash_v1, hash_v2) out of 3 site visitors in the range.
    expect($goals->first()['conversion_rate'])->toBe(66.7);
});

test('date boundaries are inclusive on both ends', function () {
    $site = Site::factory()->create(['domain' => 'boundaries.com']);
    $start = Carbon::parse('2026-07-01 00:00:00');
    $end = Carbon::parse('2026-07-03 23:59:59');

    Event::create([
        'site_id' => $site->id,
        'path' => '/exact-start',
        'visitor_hash' => 'hash_a',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-01 00:00:00'),
    ]);
    Event::create([
        'site_id' => $site->id,
        'path' => '/exact-end',
        'visitor_hash' => 'hash_b',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-03 23:59:59'),
    ]);
    Event::create([
        'site_id' => $site->id,
        'path' => '/just-before',
        'visitor_hash' => 'hash_c',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-06-30 23:59:59'),
    ]);
    Event::create([
        'site_id' => $site->id,
        'path' => '/just-after',
        'visitor_hash' => 'hash_d',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-04 00:00:00'),
    ]);

    $pageviews = $this->service->getPageviews($site, $start, $end);
    expect($pageviews)->toBe(2);

    $daily = $this->service->getDailyPageviews($site, $start, $end);
    expect($daily[0]['pageviews'])->toBe(1); // 07-01
    expect($daily[2]['pageviews'])->toBe(1); // 07-03
});

test('cache keys separate filter variants without collisions', function () {
    $home = $this->service->getPageviews($this->siteA, $this->startDate, $this->endDate, ['path' => '/home']);
    $pricing = $this->service->getPageviews($this->siteA, $this->startDate, $this->endDate, ['path' => '/pricing']);
    $all = $this->service->getPageviews($this->siteA, $this->startDate, $this->endDate);

    expect($home)->toBe(2);
    expect($pricing)->toBe(1);
    expect($all)->toBe(5);

    // Interleaved re-queries must return the same values (no cross-contamination).
    expect($this->service->getPageviews($this->siteA, $this->startDate, $this->endDate, ['path' => '/home']))->toBe(2);
    expect($this->service->getPageviews($this->siteA, $this->startDate, $this->endDate))->toBe(5);
    expect($this->service->getPageviews($this->siteA, $this->startDate, $this->endDate, ['path' => '/pricing']))->toBe(1);
});

test('cache keys separate limit variants without collisions', function () {
    $limit10 = $this->service->getTopPages($this->siteA, $this->startDate, $this->endDate, 10);
    $limit50 = $this->service->getTopPages($this->siteA, $this->startDate, $this->endDate, 50);

    expect($limit10)->toHaveCount(3);
    expect($limit50)->toHaveCount(3);
    expect($limit50->first())->toBe([
        'path' => '/checkout',
        'count' => 2,
        'percentage' => 40.0,
    ]);
});

test('cache keys separate different sites', function () {
    $siteAViews = $this->service->getPageviews($this->siteA, $this->startDate, $this->endDate);
    $siteBViews = $this->service->getPageviews($this->siteB, $this->startDate, $this->endDate);

    expect($siteAViews)->toBe(5);
    expect($siteBViews)->toBe(1);

    expect($this->service->getPageviews($this->siteA, $this->startDate, $this->endDate))->toBe(5);
});

test('clearCache invalidates getCustomEvents', function () {
    expect($this->service->getCustomEvents($this->siteA, $this->startDate, $this->endDate))->toHaveCount(1);

    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/signup',
        'visitor_hash' => 'hash_v4',
        'device_type' => DeviceType::Desktop,
        'metadata' => ['name' => 'signup_click', 'props' => []],
        'created_at' => Carbon::parse('2026-07-02 16:00:00'),
    ]);
    $this->service->clearCache($this->siteA);

    expect($this->service->getCustomEvents($this->siteA, $this->startDate, $this->endDate))->toHaveCount(2);
});

test('clearCache invalidates getCustomEventSummary', function () {
    expect($this->service->getCustomEventSummary($this->siteA, $this->startDate, $this->endDate)['total_custom_events'])->toBe(2);

    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/signup',
        'visitor_hash' => 'hash_v4',
        'device_type' => DeviceType::Desktop,
        'metadata' => ['name' => 'signup_click', 'props' => []],
        'created_at' => Carbon::parse('2026-07-02 16:00:00'),
    ]);
    $this->service->clearCache($this->siteA);

    expect($this->service->getCustomEventSummary($this->siteA, $this->startDate, $this->endDate)['total_custom_events'])->toBe(3);
});

test('clearCache invalidates getCustomEventsList', function () {
    expect($this->service->getCustomEventsList($this->siteA, $this->startDate, $this->endDate))->toHaveCount(1);

    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/signup',
        'visitor_hash' => 'hash_v4',
        'device_type' => DeviceType::Desktop,
        'metadata' => ['name' => 'signup_click', 'props' => []],
        'created_at' => Carbon::parse('2026-07-02 16:00:00'),
    ]);
    $this->service->clearCache($this->siteA);

    expect($this->service->getCustomEventsList($this->siteA, $this->startDate, $this->endDate))->toHaveCount(2);
});

test('clearCache invalidates getCustomEventTimeline', function () {
    $timeline = $this->service->getCustomEventTimeline($this->siteA, $this->startDate, $this->endDate);
    expect($timeline[1]['count'])->toBe(2);

    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/checkout',
        'visitor_hash' => 'hash_v4',
        'device_type' => DeviceType::Desktop,
        'metadata' => ['name' => 'purchase_click', 'props' => ['plan' => 'free']],
        'created_at' => Carbon::parse('2026-07-02 17:00:00'),
    ]);
    $this->service->clearCache($this->siteA);

    $freshTimeline = $this->service->getCustomEventTimeline($this->siteA, $this->startDate, $this->endDate);
    expect($freshTimeline[1]['count'])->toBe(3);
});

test('clearCache invalidates getCustomEventPropertyKeys', function () {
    expect($this->service->getCustomEventPropertyKeys($this->siteA, 'purchase_click', $this->startDate, $this->endDate))->toBe(['plan']);

    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/checkout',
        'visitor_hash' => 'hash_v4',
        'device_type' => DeviceType::Desktop,
        'metadata' => ['name' => 'purchase_click', 'props' => ['plan' => 'free', 'theme' => 'dark']],
        'created_at' => Carbon::parse('2026-07-02 17:00:00'),
    ]);
    $this->service->clearCache($this->siteA);

    expect($this->service->getCustomEventPropertyKeys($this->siteA, 'purchase_click', $this->startDate, $this->endDate))->toBe(['plan', 'theme']);
});

test('clearCache invalidates getCustomEventPropertyBreakdown', function () {
    $breakdown = $this->service->getCustomEventPropertyBreakdown($this->siteA, 'purchase_click', 'plan', $this->startDate, $this->endDate);
    expect($breakdown)->toHaveCount(2);

    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/checkout',
        'visitor_hash' => 'hash_v4',
        'device_type' => DeviceType::Desktop,
        'metadata' => ['name' => 'purchase_click', 'props' => ['plan' => 'free']],
        'created_at' => Carbon::parse('2026-07-02 17:00:00'),
    ]);
    $this->service->clearCache($this->siteA);

    $fresh = $this->service->getCustomEventPropertyBreakdown($this->siteA, 'purchase_click', 'plan', $this->startDate, $this->endDate);
    expect($fresh)->toHaveCount(3);
    expect($fresh->pluck('value')->all())->toContain('free');
});

test('clearCache invalidates getCustomEventLogs', function () {
    expect($this->service->getCustomEventLogs($this->siteA, $this->startDate, $this->endDate))->toHaveCount(2);

    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/checkout',
        'visitor_hash' => 'hash_v4',
        'device_type' => DeviceType::Desktop,
        'metadata' => ['name' => 'purchase_click', 'props' => ['plan' => 'free']],
        'created_at' => Carbon::parse('2026-07-02 18:00:00'),
    ]);
    $this->service->clearCache($this->siteA);

    expect($this->service->getCustomEventLogs($this->siteA, $this->startDate, $this->endDate))->toHaveCount(3);
});

test('clearCache invalidates getGoals', function () {
    Goal::create([
        'site_id' => $this->siteA->id,
        'name' => 'Home Views',
        'target_type' => 'path',
        'target_value' => '/home',
    ]);

    expect($this->service->getGoals($this->siteA, $this->startDate, $this->endDate)->first()['completions'])->toBe(2);

    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/home',
        'visitor_hash' => 'hash_v4',
        'device_type' => DeviceType::Desktop,
        'created_at' => Carbon::parse('2026-07-02 16:00:00'),
    ]);
    $this->service->clearCache($this->siteA);

    expect($this->service->getGoals($this->siteA, $this->startDate, $this->endDate)->first()['completions'])->toBe(3);
});

test('untagged fallback clearCache invalidates common-period keys on non-tag drivers', function () {
    // The database store does not support tags, exercising the fallback forget
    // loop in clearCache() (today's range is one of the common periods).
    config(['cache.default' => 'database']);

    $start = now()->startOfDay();
    $end = now()->endOfDay();

    expect($this->service->getPageviews($this->siteA, $start, $end))->toBe(0);

    Event::create([
        'site_id' => $this->siteA->id,
        'path' => '/today',
        'visitor_hash' => 'hash_today',
        'device_type' => DeviceType::Desktop,
        'created_at' => now(),
    ]);

    // Still cached (stale).
    expect($this->service->getPageviews($this->siteA, $start, $end))->toBe(0);

    $this->service->clearCache($this->siteA);

    expect($this->service->getPageviews($this->siteA, $start, $end))->toBe(1);
});

test('metric invariants hold for the seeded dataset', function () {
    $overview = $this->service->getOverview($this->siteA, $this->startDate, $this->endDate);

    expect($overview['total_pageviews'])->toBeGreaterThanOrEqual($overview['unique_visitors']);
    expect($overview['bounce_rate'])->toBeGreaterThanOrEqual(0.0);
    expect($overview['bounce_rate'])->toBeLessThanOrEqual(100.0);

    Goal::create([
        'site_id' => $this->siteA->id,
        'name' => 'Home Views',
        'target_type' => 'path',
        'target_value' => '/home',
    ]);
    // getOverview() cached an empty goals list before the goal existed.
    $this->service->clearCache($this->siteA);

    $goals = $this->service->getGoals($this->siteA, $this->startDate, $this->endDate);
    expect($goals->first()['conversion_rate'])->toBeGreaterThanOrEqual(0.0);
    expect($goals->first()['conversion_rate'])->toBeLessThanOrEqual(100.0);

    foreach ($overview['top_pages'] as $page) {
        expect($page['percentage'])->toBeGreaterThanOrEqual(0.0);
        expect($page['percentage'])->toBeLessThanOrEqual(100.0);
    }
});
