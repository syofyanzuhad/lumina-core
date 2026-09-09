<?php

namespace Lumina\Core\Support;

class ReferrerHelper
{
    /**
     * Known referrer domain mappings to clean platform names.
     *
     * @var array<string, string>
     */
    protected static array $knownPlatforms = [
        't.co' => 'X (Twitter)',
        'twitter.com' => 'X (Twitter)',
        'x.com' => 'X (Twitter)',

        'l.instagram.com' => 'Instagram',
        'lm.instagram.com' => 'Instagram',
        'instagram.com' => 'Instagram',

        'l.facebook.com' => 'Facebook',
        'lm.facebook.com' => 'Facebook',
        'facebook.com' => 'Facebook',
        'fb.me' => 'Facebook',

        'linkedin.com' => 'LinkedIn',
        'lnkd.in' => 'LinkedIn',

        'news.ycombinator.com' => 'Hacker News',
        'ycombinator.com' => 'Hacker News',

        'github.com' => 'GitHub',
        'reddit.com' => 'Reddit',
        'old.reddit.com' => 'Reddit',
        'out.reddit.com' => 'Reddit',

        'google.com' => 'Google',
        'google.co.id' => 'Google',
        'google.co.uk' => 'Google',
        'google.de' => 'Google',
        'google.fr' => 'Google',

        'bing.com' => 'Bing',
        'yahoo.com' => 'Yahoo',
        'duckduckgo.com' => 'DuckDuckGo',
        'youtube.com' => 'YouTube',
        'youtu.be' => 'YouTube',

        't.me' => 'Telegram',
        'web.telegram.org' => 'Telegram',
        'wa.me' => 'WhatsApp',
        'api.whatsapp.com' => 'WhatsApp',

        'threads.net' => 'Threads',
        'medium.com' => 'Medium',
        'dev.to' => 'DEV Community',
        'producthunt.com' => 'Product Hunt',
    ];

    /**
     * Parse raw referrer URL into a human-readable platform name or clean domain.
     */
    public static function parseName(?string $referrer): string
    {
        if (empty($referrer) || $referrer === 'Direct' || $referrer === 'direct') {
            return 'Direct / None';
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (! $host) {
            $host = strtolower(trim($referrer));
        } else {
            $host = strtolower(trim($host));
        }

        // Strip www. prefix
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // Check exact known platform map
        if (isset(static::$knownPlatforms[$host])) {
            return static::$knownPlatforms[$host];
        }

        // Subdomain matching for known domains (e.g. m.facebook.com -> Facebook)
        foreach (static::$knownPlatforms as $domain => $platformName) {
            if (str_ends_with($host, '.'.$domain)) {
                return $platformName;
            }
        }

        return $host;
    }

    /**
     * Get all domains matching a platform name, or return empty array if not a known platform.
     *
     * @return array<int, string>
     */
    public static function getDomainsForPlatform(string $platform): array
    {
        $domains = [];
        foreach (static::$knownPlatforms as $domain => $name) {
            if (strcasecmp($name, $platform) === 0) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }
}
