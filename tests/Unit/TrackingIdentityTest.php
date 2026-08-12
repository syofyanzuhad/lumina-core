<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Lumina\Core\Support\TrackingIdentity;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();

    // Deterministic salt so the fallback hash is stable within a test.
    Cache::put('lumina_visitor_salt', 'test-salt');
});

/**
 * Build a collect request with the given identity headers / input.
 */
function identityRequest(?string $visitor = null, ?string $session = null, bool $asInput = false): Request
{
    $server = ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_USER_AGENT' => 'TestAgent/1.0'];

    if ($asInput) {
        $query = array_filter([
            'visitor' => $visitor,
            'session' => $session,
        ], fn ($v) => $v !== null);

        return Request::create('/collect', 'GET', $query, server: $server);
    }

    $request = Request::create('/collect', 'GET', server: $server);

    if ($visitor !== null) {
        $request->headers->set(TrackingIdentity::VISITOR_HEADER, $visitor);
    }

    if ($session !== null) {
        $request->headers->set(TrackingIdentity::SESSION_HEADER, $session);
    }

    return $request;
}

test('a valid opaque visitor id from a header is honored', function () {
    $identity = TrackingIdentity::resolve(identityRequest('client_visitor_abc'));

    expect($identity['visitor_id'])->toBe('client_visitor_abc');
    expect($identity['visitor_hash'])->toBe('client_visitor_abc');
});

test('a valid opaque visitor id from request input is honored', function () {
    $identity = TrackingIdentity::resolve(identityRequest('client_visitor_abc', asInput: true));

    expect($identity['visitor_id'])->toBe('client_visitor_abc');
});

test('the session id is honored when present and null when absent', function () {
    $withSession = TrackingIdentity::resolve(identityRequest('v1', 'session_xyz'));
    $withoutSession = TrackingIdentity::resolve(identityRequest('v1'));

    expect($withSession['session_id'])->toBe('session_xyz');
    expect($withoutSession['session_id'])->toBeNull();
});

test('an empty visitor id falls back to a hashed identity', function () {
    $identity = TrackingIdentity::resolve(identityRequest(''));

    expect($identity['visitor_id'])->toBe($identity['visitor_hash']);
    expect(strlen($identity['visitor_id']))->toBe(64);
    expect($identity['visitor_id'])->toMatch('/^[a-f0-9]{64}$/');
});

test('a whitespace-only visitor id falls back to a hashed identity', function () {
    $identity = TrackingIdentity::resolve(identityRequest('   '));

    expect($identity['visitor_id'])->toBe($identity['visitor_hash']);
    expect(strlen($identity['visitor_id']))->toBe(64);
});

test('an over-long visitor id (>100 chars) is rejected', function () {
    $tooLong = str_repeat('a', 101);

    $identity = TrackingIdentity::resolve(identityRequest($tooLong));

    expect($identity['visitor_id'])->toBe($identity['visitor_hash']);
    expect(strlen($identity['visitor_id']))->toBe(64);
});

test('a 100-char opaque id is accepted but truncated to 64 to fit the schema', function () {
    $maxLength = str_repeat('x', 100);

    $identity = TrackingIdentity::resolve(identityRequest($maxLength));

    expect($identity['visitor_id'])->toBe(substr($maxLength, 0, 64));
    expect(strlen($identity['visitor_id']))->toBe(64);
});

test('opaque ids containing illegal characters are rejected', function ($value) {
    $identity = TrackingIdentity::resolve(identityRequest($value));

    expect($identity['visitor_id'])->toBe($identity['visitor_hash']);
})->with([
    'email-like' => ['visitor@example.com'],
    'ip-like' => ['203.0.113.5'],
    'spaces inside' => ['abc def'],
    'slash' => ['abc/def'],
    'unicode' => ['café'],
    'dots' => ['a.b'],
]);

test('non-string identity input is rejected', function () {
    $request = Request::create('/collect', 'GET', ['visitor' => ['a', 'b']], server: ['REMOTE_ADDR' => '203.0.113.5']);

    $identity = TrackingIdentity::resolve($request);

    expect($identity['visitor_id'])->toBe($identity['visitor_hash']);
    expect(strlen($identity['visitor_id']))->toBe(64);
});

test('the fallback hash is stable for the same ip, user-agent and scope', function () {
    $first = TrackingIdentity::resolve(identityRequest());
    $second = TrackingIdentity::resolve(identityRequest());

    expect($first['visitor_id'])->toBe($second['visitor_id']);
});

test('the fallback hash differs across scopes (different sites)', function () {
    $siteA = TrackingIdentity::resolve(identityRequest(), '10');
    $siteB = TrackingIdentity::resolve(identityRequest(), '20');

    expect($siteA['visitor_id'])->not->toBe($siteB['visitor_id']);
});

test('resolve always returns a fresh event id', function () {
    $first = TrackingIdentity::resolve(identityRequest('visitor_1'));
    $second = TrackingIdentity::resolve(identityRequest('visitor_1'));

    expect($first['event_id'])->not->toBe($second['event_id']);
    expect($first['event_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});
