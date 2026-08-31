<?php

namespace Lumina\Core\Livewire;

use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Lumina\Core\Models\Site;
use Lumina\Core\Services\AnalyticsService;
use Lumina\Core\Support\DateRangeHelper;

class Dashboard extends Component
{
    public Site $site;

    public string $period = '30d';

    public ?string $startDate = null;

    public ?string $endDate = null;

    public string $activeTab = 'overview';

    public ?string $selectedEvent = null;

    public ?string $selectedPropertyKey = null;

    public function mount(Site $site, string $period = '30d'): void
    {
        $this->site = $site;
        $this->period = $period;
    }

    public function setPeriod(string $period): void
    {
        $this->period = $period;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function selectEvent(?string $eventName): void
    {
        $this->selectedEvent = $eventName;
        $this->selectedPropertyKey = null; // Reset property key when event changes
    }

    public function selectPropertyKey(?string $key): void
    {
        $this->selectedPropertyKey = $key;
    }

    public function render(AnalyticsService $analytics): View
    {
        [$start, $end] = $this->resolveDateRange();

        $data = [
            'period' => $this->period,
            'start' => $start,
            'end' => $end,
        ];

        if ($this->activeTab === 'overview') {
            $data = array_merge($data, $analytics->getOverview($this->site, $start, $end));
        } elseif ($this->activeTab === 'events') {
            $data['custom_event_summary'] = $analytics->getCustomEventSummary($this->site, $start, $end, $this->selectedEvent);
            $data['custom_events_list'] = $analytics->getCustomEventsList($this->site, $start, $end);
            $data['custom_event_timeline'] = $analytics->getCustomEventTimeline($this->site, $start, $end, $this->selectedEvent);

            if ($this->selectedEvent) {
                $data['custom_event_property_keys'] = $analytics->getCustomEventPropertyKeys($this->site, $this->selectedEvent, $start, $end);

                if ($this->selectedPropertyKey) {
                    $data['custom_event_property_breakdown'] = $analytics->getCustomEventPropertyBreakdown($this->site, $this->selectedEvent, $this->selectedPropertyKey, $start, $end);
                } else {
                    $data['custom_event_property_breakdown'] = [];
                }
            } else {
                $data['custom_event_property_keys'] = [];
                $data['custom_event_property_breakdown'] = [];
            }

            $data['custom_event_logs'] = $analytics->getCustomEventLogs($this->site, $start, $end, $this->selectedEvent);
        }

        return view('lumina::livewire.dashboard', $data);
    }

    /**
     * @return array{CarbonInterface, CarbonInterface}
     */
    protected function resolveDateRange(): array
    {
        return DateRangeHelper::resolve($this->period, $this->startDate, $this->endDate);
    }
}
