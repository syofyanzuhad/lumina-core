<?php

namespace Lumina\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Lumina\Core\Enums\DeviceType;
use Lumina\Core\Jobs\InsertEvent;
use Lumina\Core\Models\Site;
use Lumina\Core\Support\BotDetector;
use Lumina\Core\Support\TrackingIdentity;

class CollectController extends Controller
{
    /**
     * Maximum accepted pageview events per IP per window.
     */
    private const IP_MAX_ATTEMPTS = 120;

    private const IP_DECAY_SECONDS = 60;

    /**
     * @return array<string, string>
     */
    protected function getCorsHeaders(Request $request): array
    {
        $origin = $request->headers->get('Origin')
            ?? $request->headers->get('origin')
            ?? $request->server->get('HTTP_ORIGIN');

        // Reflecting the specific origin (never a wildcard) is required to
        // pair with Access-Control-Allow-Credentials; when there is no Origin
        // header, credentials are not sent by browsers anyway.
        return [
            'Access-Control-Allow-Origin' => $origin ?: '',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With',
            'Access-Control-Allow-Credentials' => 'true',
            'Vary' => 'Origin',
        ];
    }

    public function __invoke(Request $request): JsonResponse
    {
        $corsHeaders = $this->getCorsHeaders($request);

        if ($request->isMethod('OPTIONS')) {
            return response()->json(null, 204, $corsHeaders);
        }

        if ($request->isMethod('GET')) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Lumina Analytics Collector API is active.',
            ], 200, $corsHeaders);
        }

        if (BotDetector::isBot($request->userAgent())) {
            return response()->json(null, 204, $corsHeaders);
        }

        // Per-IP rate limit on the ingest path (the middleware already limits
        // server-side pageviews, but this endpoint accepts unauthenticated
        // POSTs that could otherwise inflate stats or flood the queue).
        $ipKey = 'lumina_collect:'.$request->ip();
        if (! RateLimiter::attempt($ipKey, self::IP_MAX_ATTEMPTS, fn () => true, self::IP_DECAY_SECONDS)) {
            return response()->json(null, 204, $corsHeaders);
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'path' => ['required', 'string', 'max:255'],
            'referrer' => ['nullable', 'string', 'max:255'],
            'screen_width' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $domain = strtolower(trim($validated['domain']));
        $site = Site::cachedByDomain($domain);

        if (! $site) {
            return response()->json([
                'message' => 'Unregistered domain.',
            ], 422, $corsHeaders);
        }

        $identity = TrackingIdentity::resolve($request, (string) $site->id);

        $userAgent = $request->userAgent() ?? '';

        $screenWidth = $request->input('screen_width');
        if ($screenWidth !== null && (int) $screenWidth > 0) {
            $deviceType = DeviceType::fromScreenWidth((int) $screenWidth);
        } else {
            $deviceType = DeviceType::fromUserAgent($userAgent);
        }

        // Trust boundary: first-party X-Country override is always honored;
        // edge-proxy country headers only when the request is from a trusted proxy.
        $country = $request->header('X-Country');
        if ($country === null && $request->isFromTrustedProxy()) {
            $country = $request->header('CF-IPCountry')
                ?? $request->header('X-Vercel-IP-Country');
        }

        $cleanPath = parse_url($validated['path'], PHP_URL_PATH) ?: '/';
        $path = '/'.ltrim($cleanPath, '/');

        $metadata = null;
        if (! empty($validated['name'])) {
            $metadata = [
                'name' => $validated['name'],
                'props' => $validated['metadata'] ?? null,
            ];
        } elseif (! empty($validated['metadata'])) {
            $metadata = $validated['metadata'];
        }

        try {
            InsertEvent::dispatch(
                siteId: $site->id,
                path: $path,
                referrer: $validated['referrer'] ?? null,
                visitorHash: $identity['visitor_hash'],
                visitorId: $identity['visitor_id'],
                sessionId: $identity['session_id'],
                eventId: $identity['event_id'],
                deviceType: $deviceType,
                country: $country,
                metadata: $metadata,
                userAgent: $userAgent,
                ip: $request->ip(),
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(null, 204, $corsHeaders);
    }
}
