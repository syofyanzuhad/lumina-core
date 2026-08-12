<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lumina\Core\Support\CountryResolver;
use Lumina\Core\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['lumina.geoip.driver' => 'ip-api']);
    config(['cache.default' => 'array']);
    Cache::flush();
});

// The Http facade Mockery swap (lookup-exception test) must not leak into
// other tests, so close Mockery after every test in this file.
afterEach(function () {
    Mockery::close();
});

test('ip-api driver resolves the country code for a public IP', function () {
    Http::fake([
        'ip-api.com/*' => Http::response(['countryCode' => 'ID'], 200),
    ]);

    $country = (new CountryResolver)->resolve('203.0.113.10');

    expect($country)->toBe('ID');
});

test('the country code is cached per IP for 24 hours', function () {
    Http::fake([
        'ip-api.com/*' => Http::response(['countryCode' => 'ID'], 200),
    ]);

    $resolver = new CountryResolver;

    $resolver->resolve('203.0.113.10');
    $resolver->resolve('203.0.113.10');

    Http::assertSentCount(1);
    expect(Cache::get('geoip:203.0.113.10'))->toBe('ID');
});

test('a cached country is reused without a second HTTP request', function () {
    Cache::put('geoip:203.0.113.10', 'US');
    Http::fake();

    $country = (new CountryResolver)->resolve('203.0.113.10');

    expect($country)->toBe('US');
    Http::assertNothingSent();
});

test('the disabled driver never performs network lookups', function () {
    config(['lumina.geoip.driver' => 'disabled']);
    Http::fake();

    $country = (new CountryResolver)->resolve('203.0.113.10');

    expect($country)->toBeNull();
    Http::assertNothingSent();
});

test('private and reserved IPs are never looked up', function ($ip) {
    Http::fake([
        'ip-api.com/*' => Http::response(['countryCode' => 'ID'], 200),
    ]);

    $country = (new CountryResolver)->resolve($ip);

    expect($country)->toBeNull();
    Http::assertNothingSent();
})->with([
    'private IPv4' => ['192.168.1.10'],
    'loopback' => ['127.0.0.1'],
    'link-local' => ['169.254.10.10'],
    'unspecified' => ['0.0.0.0'],
    'broadcast' => ['255.255.255.255'],
    'reserved IPv4' => ['240.0.0.1'],
    'private IPv6' => ['fc00::1'],
    'loopback IPv6' => ['::1'],
]);

test('null and empty IPs resolve to null without any request', function (?string $ip) {
    Http::fake();

    $country = (new CountryResolver)->resolve($ip);

    expect($country)->toBeNull();
    Http::assertNothingSent();
})->with([
    'null' => [null],
    'empty string' => [''],
]);

test('a failed lookup degrades to null instead of throwing', function () {
    Http::fake([
        'ip-api.com/*' => Http::response([], 500),
    ]);

    $country = (new CountryResolver)->resolve('203.0.113.10');

    expect($country)->toBeNull();
});

test('a response without a countryCode resolves to null', function () {
    Http::fake([
        'ip-api.com/*' => Http::response(['status' => 'fail'], 200),
    ]);

    $country = (new CountryResolver)->resolve('203.0.113.10');

    expect($country)->toBeNull();
});

test('a lookup exception degrades to null instead of throwing', function () {
    // Throwing inside an Http::fake() callback segfaults PHP on this stack
    // (signal 11), so force the HTTP call itself to throw via Mockery.
    Http::shouldReceive('timeout')->andReturnSelf();
    Http::shouldReceive('get')->andThrow(new RuntimeException('connection refused'));

    $country = (new CountryResolver)->resolve('203.0.113.10');

    expect($country)->toBeNull();
});
