<?php

namespace Lumina\Core\Tests;

use Illuminate\Foundation\Application;
use Livewire\LivewireServiceProvider;
use Lumina\Core\LuminaCoreServiceProvider;

/**
 * Base test case shared between two execution environments:
 *
 *  1. Standalone package testing — `vendor/bin/pest` in this directory. The
 *     Testbench branch below boots an isolated Laravel application with the
 *     package's service provider (and Livewire, required by the dashboard
 *     component tests) registered, against an in-memory SQLite database.
 *
 *  2. Host application testing — `php artisan test` in the monorepo, where
 *     this package is consumed as a path repository. There the host app's
 *     `Tests\TestCase` provides the booted application, database, and Pest
 *     integration, so the exact same test files run unchanged.
 *
 * The `class_exists` guard picks the branch at file-load time, so only one
 * parent class is ever bound per process.
 */
use function Orchestra\Testbench\after_resolving;
use function Orchestra\Testbench\default_migration_path;

if (class_exists(\Orchestra\Testbench\TestCase::class)) {
    class TestCase extends \Orchestra\Testbench\TestCase
    {
        /**
         * Register the package's service provider, plus Livewire when it is
         * installed (the dashboard component and its tests depend on it).
         *
         * @param  Application  $app
         * @return array<int, class-string>
         */
        protected function getPackageProviders($app): array
        {
            $providers = [
                LuminaCoreServiceProvider::class,
            ];

            if (class_exists(LivewireServiceProvider::class)) {
                $providers[] = LivewireServiceProvider::class;
            }

            return $providers;
        }

        /**
         * Default test environment: in-memory SQLite and the array cache store.
         *
         * The array store supports cache tags, which makes the AnalyticsService
         * cache invalidation exact and keeps the suite hermetic (no shared
         * cache table across tests). GeoIP is disabled so no test ever makes
         * a network call unless it explicitly configures a driver.
         */
        protected function defineEnvironment($app): void
        {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
            $app['config']->set('cache.default', 'array');
            $app['config']->set('lumina.geoip.driver', 'disabled');

            $app['config']->set('app.key', 'base64:4TzR41xS35B4Q3qH+54B/gY2148Qn8aK7gY2148Qn8a=');
            $app['config']->set('auth.providers.users.model', User::class);

            // RefreshDatabase's migrate:fresh must create Testbench's default
            // Laravel tables (users, cache, jobs) alongside the package's own
            // migrations — the sites table has a foreign key to `users`. Register
            // the path on the migrator before it is resolved so a single fresh
            // pass builds the complete schema.
            after_resolving($app, 'migrator', static function ($migrator): void {
                $migrator->path(default_migration_path());
            });
        }
    }
} else {
    class TestCase extends \Tests\TestCase
    {
        //
    }
}
