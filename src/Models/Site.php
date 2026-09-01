<?php

namespace Lumina\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Lumina\Core\Database\Factories\SiteFactory;

/**
 * @property int $id
 * @property string $domain
 * @property int $owner_id
 * @property bool $is_public
 * @property string|null $share_token
 * @property string|null $share_password
 * @property string|null $api_token
 * @property int|null $retention_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['domain', 'owner_id', 'is_public', 'share_token', 'share_password', 'api_token'])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $table = 'sites';

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'share_password',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'has_password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    protected static function newFactory(): SiteFactory
    {
        return SiteFactory::new();
    }

    /**
     * Cache-aware domain lookup used by the tracking pipeline.
     *
     * Caches the full site attributes (excluding share_password) so that
     * cache hits never fire a DB query. The model is re-hydrated from the
     * cached array via newFromBuilder(), which is the same path Eloquent
     * uses internally after a SELECT — casts, appends, and accessors all
     * apply normally on first access.
     *
     * The cached entry is invalidated automatically by the saved/deleted
     * hooks below, so newly created or renamed sites are trackable
     * immediately. The 'lumina_site_lookup:' namespace deliberately differs
     * from the 'lumina_site:' rate-limiter keys used by TrackPageview so
     * the two can never collide in the cache store.
     */
    public static function cachedByDomain(string $domain): ?self
    {
        $domain = Str::lower($domain);
        $cacheKey = 'lumina_site_lookup:'.$domain;

        /** @var mixed $attributes */
        $attributes = Cache::remember($cacheKey, 3600, function () use ($domain) {
            return static::where('domain', $domain)
                ->first(['id', 'domain', 'owner_id', 'is_public', 'share_token',
                    'share_password', 'api_token', 'retention_days',
                    'created_at', 'updated_at'])
                ?->getAttributes();
        });

        // Defensive check: if legacy/corrupt scalar data exists in cache, flush and re-query
        if (! is_array($attributes) || ! isset($attributes['id'])) {
            if ($attributes !== null) {
                Cache::forget($cacheKey);

                return static::cachedByDomain($domain);
            }

            return null;
        }

        $site = (new self)->newFromBuilder($attributes);

        return $site;
    }

    /**
     * Invalidate the cached domain lookup on any persistence change.
     */
    protected static function booted(): void
    {
        static::saved(function (self $site) {
            Cache::forget('lumina_site_lookup:'.Str::lower($site->domain));
        });

        static::deleted(function (self $site) {
            Cache::forget('lumina_site_lookup:'.Str::lower($site->domain));
        });

        static::updating(function (self $site) {
            if ($site->isDirty('domain')) {
                Cache::forget('lumina_site_lookup:'.Str::lower($site->getOriginal('domain')));
            }
        });
    }

    /**
     * Get the owner of the site.
     *
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model', User::class);

        return $this->belongsTo($userModel, 'owner_id');
    }

    /**
     * Get the events for the site.
     *
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Get the goals for the site.
     *
     * @return HasMany<Goal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /**
     * Interact with the site's domain.
     *
     * @return Attribute<string, string>
     */
    protected function domain(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::lower($value),
        );
    }

    /**
     * Check if the site has a share password set.
     */
    public function hasSharePassword(): bool
    {
        return ! empty($this->share_password);
    }

    /**
     * Accessor for has_password attribute.
     */
    public function getHasPasswordAttribute(): bool
    {
        return $this->hasSharePassword();
    }

    /**
     * Check if the site is publicly accessible via share token.
     */
    public function isPubliclyAccessible(): bool
    {
        return (bool) $this->is_public && ! empty($this->share_token);
    }

    /**
     * Generate a new 32-character random share token.
     */
    public function generateShareToken(): string
    {
        return Str::random(32);
    }

    /**
     * Generate a new 64-character API token for programmatic access.
     */
    public function generateApiToken(): string
    {
        return 'lum_'.Str::random(60);
    }
}
