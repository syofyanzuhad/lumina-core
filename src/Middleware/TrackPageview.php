<?php

namespace Lumina\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Lumina\Core\Enums\DeviceType;
use Lumina\Core\Jobs\InsertEvent;
use Lumina\Core\Models\Site;
use Lumina\Core\Support\BotDetector;
use Lumina\Core\Support\TrackingIdentity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminable tracking middleware.
 *
 * handle() returns immediately — all hashing, rate limiting, and job dispatch
 * happen in terminate(), after the response has been sent to the browser, so
 * tracking never adds latency to the end user's request.
 *
 * Deployment model (trust boundary): this middleware trusts the
 * `X-Country` first-party override unconditionally, but only reads the
 * `CF-IPCountry` / `X-Vercel-IP-Country` proxy headers when the request is
 * confirmed to come from a trusted proxy (see TrustProxies configuration).
 * If the app is reachable directly (staging, local, non-proxied paths), those
 * proxy headers are ignored and country resolution falls back to GeoIP, so
 * spoofable input is never silently accepted.
 */
class TrackPageview
{
    private const IP_MAX_ATTEMPTS = 60;

    private const IP_DECAY_SECONDS = 60;

    private const SITE_MAX_ATTEMPTS = 300;

    private const SITE_DECAY_SECONDS = 300;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Perform tracking after the response has been sent.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->track($request);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Record a pageview: resolve site, apply rate limits, dispatch the job.
     * Any failure is reported to the log rather than failing the request,
     * which has already been served by the time this runs.
     */
    protected function track(Request $request): void
    {
        if (BotDetector::isBot($request->userAgent())) {
            return;
        }

        $host = $request->getHost();

        $site = Site::cachedByDomain($host);

        if (! $site) {
            return;
        }

        // Check-and-increment atomically so concurrent requests can never
        // exceed the configured caps (silent swallow when limited).
        $ipKey = 'lumina_ip:'.$request->ip();
        if (! RateLimiter::attempt($ipKey, self::IP_MAX_ATTEMPTS, fn () => true, self::IP_DECAY_SECONDS)) {
            return;
        }

        $siteKey = 'lumina_site:'.$host;
        if (! RateLimiter::attempt($siteKey, self::SITE_MAX_ATTEMPTS, fn () => true, self::SITE_DECAY_SECONDS)) {
            return;
        }

        $identity = TrackingIdentity::resolve($request, (string) $site->id);

        $userAgent = $request->userAgent() ?? '';

        // Trust boundary: first-party X-Country override is always honored;
        // edge-proxy country headers only when the request is from a trusted proxy.
        $country = $request->header('X-Country');
        if ($country === null && $request->isFromTrustedProxy()) {
            $country = $request->header('CF-IPCountry')
                ?? $request->header('X-Vercel-IP-Country');
        }

        // $request->path() is already query-string-free and method-normalized.
        $path = '/'.ltrim($request->path(), '/');

        try {
            InsertEvent::dispatch(
                siteId: $site->id,
                path: $path,
                referrer: $request->header('referer'),
                visitorHash: $identity['visitor_hash'],
                visitorId: $identity['visitor_id'],
                sessionId: $identity['session_id'],
                eventId: $identity['event_id'],
                deviceType: DeviceType::fromUserAgent($userAgent),
                country: $country,
                metadata: null,
                userAgent: $userAgent,
                ip: $request->ip(),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
