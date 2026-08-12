<?php

namespace Lumina\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Lumina\Core\Database\Factories\EventFactory;
use Lumina\Core\Enums\DeviceType;

/**
 * @property int $id
 * @property int $site_id
 * @property string $path
 * @property string|null $clean_path
 * @property string|null $referrer
 * @property string $visitor_hash
 * @property string|null $visitor_id
 * @property string|null $session_id
 * @property string|null $event_id
 * @property DeviceType|string $device_type
 * @property string|null $country
 * @property string|null $browser
 * @property string|null $browser_version
 * @property string|null $os
 * @property string|null $os_version
 * @property string|null $country_code
 * @property string|null $country_name
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_term
 * @property string|null $utm_content
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 *
 * Computed aliases produced by AnalyticsService aggregate queries. These are
 * not real columns; they exist so the service can read SQL aliases off
 * hydrated rows without tripping static analysis.
 * @property-read mixed $count
 * @property-read mixed $target_path
 * @property-read mixed $pageviews
 * @property-read mixed $visitors
 * @property-read mixed $code
 * @property-read mixed $event_name
 * @property-read mixed $prop_key
 * @property-read mixed $value
 * @property-read mixed $avg_duration
 * @property-read mixed $completions
 * @property-read mixed $session_key
 * @property-read mixed $first_seen
 * @property-read mixed $last_seen
 * @property-read mixed $name
 * @property-read mixed $percentage
 * @property-read mixed $date
 */
#[Fillable(['site_id', 'path', 'clean_path', 'referrer', 'visitor_hash', 'visitor_id', 'session_id', 'event_id', 'device_type', 'country', 'browser', 'browser_version', 'os', 'os_version', 'country_code', 'country_name', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'metadata', 'created_at'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $table = 'events';

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }

    /**
     * Indicates if the model should be timestamped.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_type' => DeviceType::class,
            'metadata' => 'array',
        ];
    }

    /**
     * Get the site that owns the event.
     *
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
