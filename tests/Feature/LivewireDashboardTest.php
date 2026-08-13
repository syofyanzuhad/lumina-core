<?php

namespace Lumina\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Lumina\Core\Enums\DeviceType;
use Lumina\Core\Livewire\Dashboard;
use Lumina\Core\Models\Event;
use Lumina\Core\Models\Site;
use Lumina\Core\Services\AnalyticsService;
use Lumina\Core\Tests\TestCase;

class LivewireDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['domain' => 'test-domain.com']);
    }

    public function test_livewire_dashboard_component_mounts_and_renders_empty_state_when_no_events_exist(): void
    {
        Livewire::test(Dashboard::class, ['site' => $this->site])
            ->assertSee('test-domain.com')
            ->assertSee('No data collected yet')
            ->assertSee('Add the tracking snippet');
    }

    public function test_livewire_dashboard_component_renders_metrics_and_top_pages_when_events_exist(): void
    {
        Event::create([
            'site_id' => $this->site->id,
            'path' => '/home',
            'referrer' => 'https://google.com',
            'visitor_hash' => 'hash_visitor_1',
            'device_type' => DeviceType::Desktop,
            'created_at' => Carbon::now()->subDays(2),
        ]);

        Event::create([
            'site_id' => $this->site->id,
            'path' => '/pricing',
            'referrer' => 'https://twitter.com',
            'visitor_hash' => 'hash_visitor_2',
            'device_type' => DeviceType::Mobile,
            'created_at' => Carbon::now()->subDays(1),
        ]);

        Livewire::test(Dashboard::class, ['site' => $this->site])
            ->assertSee('test-domain.com')
            ->assertDontSee('No data collected yet')
            ->assertSee('Total Pageviews')
            ->assertSee('Unique Visitors')
            ->assertSee('/home')
            ->assertSee('/pricing')
            ->assertSee('Google')
            ->assertSee('X (Twitter)');
    }

    public function test_livewire_dashboard_component_updates_reactively_when_date_period_changes(): void
    {
        Event::create([
            'site_id' => $this->site->id,
            'path' => '/old-page',
            'referrer' => null,
            'visitor_hash' => 'hash_old',
            'device_type' => DeviceType::Desktop,
            'created_at' => Carbon::now()->subDays(10),
        ]);

        $component = new Dashboard;
        $component->site = $this->site;
        $component->period = '30d';

        $this->assertEquals('30d', $component->period);
        $component->setPeriod('7d');
        $this->assertEquals('7d', $component->period);

        $view = $component->render(app(AnalyticsService::class));
        $this->assertEquals('lumina::livewire.dashboard', $view->name());
    }

    public function test_livewire_dashboard_can_switch_to_custom_events_tab(): void
    {
        $component = new Dashboard;
        $component->site = $this->site;

        $component->setTab('events');
        $this->assertEquals('events', $component->activeTab);

        $view = $component->render(app(AnalyticsService::class));
        $this->assertArrayHasKey('custom_event_summary', $view->getData());
    }

    public function test_livewire_dashboard_shows_custom_events_data(): void
    {
        Event::create([
            'site_id' => $this->site->id,
            'metadata' => ['name' => 'newsletter_signup', 'props' => ['plan' => 'free']],
            'path' => '/',
            'device_type' => DeviceType::Desktop,
            'visitor_hash' => 'hash_evt',
            'created_at' => Carbon::now(),
        ]);

        $component = new Dashboard;
        $component->site = $this->site;
        $component->setTab('events');

        $view = $component->render(app(AnalyticsService::class));
        $data = $view->getData();

        $this->assertEquals(1, $data['custom_event_summary']['total_custom_events']);
        $this->assertCount(1, $data['custom_events_list']);
        $this->assertEquals('newsletter_signup', $data['custom_events_list'][0]['name']);
    }

    public function test_livewire_dashboard_can_filter_by_custom_event_name(): void
    {
        Event::create([
            'site_id' => $this->site->id,
            'metadata' => ['name' => 'newsletter_signup', 'props' => ['plan' => 'free']],
            'path' => '/',
            'device_type' => DeviceType::Desktop,
            'visitor_hash' => 'hash_evt_1',
            'created_at' => Carbon::now(),
        ]);

        Event::create([
            'site_id' => $this->site->id,
            'metadata' => ['name' => 'purchase', 'props' => ['amount' => 50]],
            'path' => '/checkout',
            'device_type' => DeviceType::Desktop,
            'visitor_hash' => 'hash_evt_2',
            'created_at' => Carbon::now(),
        ]);

        $component = new Dashboard;
        $component->site = $this->site;
        $component->setTab('events');
        $component->selectEvent('purchase');

        $this->assertEquals('purchase', $component->selectedEvent);

        $view = $component->render(app(AnalyticsService::class));
        $data = $view->getData();

        $this->assertContains('amount', $data['custom_event_property_keys']);
    }

    public function test_livewire_dashboard_can_select_property_key(): void
    {
        Event::create([
            'site_id' => $this->site->id,
            'metadata' => ['name' => 'purchase', 'props' => ['amount' => 50, 'currency' => 'USD']],
            'path' => '/checkout',
            'device_type' => DeviceType::Desktop,
            'visitor_hash' => 'hash_evt',
            'created_at' => Carbon::now(),
        ]);

        $component = new Dashboard;
        $component->site = $this->site;
        $component->setTab('events');
        $component->selectEvent('purchase');
        $component->selectPropertyKey('currency');

        $this->assertEquals('currency', $component->selectedPropertyKey);

        $view = $component->render(app(AnalyticsService::class));
        $data = $view->getData();

        $this->assertNotEmpty($data['custom_event_property_breakdown']);
        $this->assertEquals('USD', $data['custom_event_property_breakdown'][0]['value']);
    }
}
