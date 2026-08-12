# Lumina Core Package (`lumina/core`) 📊

> Core package for Lumina web analytics. Provides models, migrations, server-side tracking middleware, JS ingestion collector, `AnalyticsService` query engine, and embedded Livewire dashboard components.

---

## 📦 Installation

Require `lumina/core` via Composer:
```bash
composer require lumina/core
```

Publish and run migrations:
```bash
php artisan vendor:publish --tag=lumina-core-migrations
php artisan migrate
```

---

## 🛠️ Middleware & Tracking

### Server-Side Middleware (Path A)
Track pageviews directly from your host application's HTTP requests:
```php
use Lumina\Core\Middleware\TrackPageview;

Route::middleware([TrackPageview::class])->group(function () {
    Route::get('/', [HomeController::class, 'index']);
});
```

### Client-Side JS Script (Path B)
Include the lightweight `< 2KB` vanilla script tag:
```html
<script defer data-domain="yourdomain.com" src="https://your-lumina.com/js/script.js"></script>
```

#### Custom Event Tracking API
```js
// Dispatch custom event to /api/collect
window.lumina('event_name', { key: 'value' });
```

---

## 📈 AnalyticsService Query API

The `Lumina\Core\Services\AnalyticsService` class provides high-performance cached aggregation queries (60s default TTL):

```php
use Lumina\Core\Services\AnalyticsService;

$analytics = app(AnalyticsService::class);

// Dashboard overview payload (Pageviews, Visitors, Referrers, Devices, OS, Browsers, Countries, Goals)
$overview = $analytics->getOverview($site, $start, $end);

// Enhanced Data Detection aggregations
$topBrowsers = $analytics->getTopBrowsers($site, $start, $end, limit: 10);
$topOS = $analytics->getTopOperatingSystems($site, $start, $end, limit: 10);
$topCountries = $analytics->getTopCountries($site, $start, $end, limit: 10);

// Custom Event tracking metrics
$customEvents = $analytics->getCustomEvents($site, $start, $end);
$propertyBreakdown = $analytics->getCustomEventPropertyBreakdown($site, 'signup_completed', 'plan', $start, $end);

// Goal & Conversion calculation
$goals = $analytics->getGoals($site, $start, $end);
```

---

## 🖼️ Embedded Livewire Component

Embed the full Lumina analytics dashboard in your Blade layouts:

```blade
<livewire:lumina-dashboard :site="$site" />
```

---

## 🧪 Testing Package Core

Run package feature tests using Pest:
```bash
vendor/bin/pest packages/lumina-core/tests/
```

---

## 📄 License
MIT License.

---

## 🧪 Testing

The package test suite runs **standalone** with [Orchestra Testbench](https://github.com/orchestral/testbench) against an in-memory SQLite database — no host Laravel application required:

```bash
composer install
composer test
```

CI runs the same command on every push/PR (see `.github/workflows/tests.yml`).

The same test files also run inside the host monorepo via `php artisan test`: `tests/TestCase.php` binds to the host application when Testbench is absent, and to Testbench when run standalone.
