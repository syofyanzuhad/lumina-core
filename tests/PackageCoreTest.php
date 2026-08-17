<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Core\Models\Event;
use Lumina\Core\Models\Site;
use Lumina\Core\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('site factory creates site model', function () {
    $site = Site::factory()->create();

    $this->assertInstanceOf(Site::class, $site);
    $this->assertNotNull($site->id);
});

test('event factory creates event model', function () {
    $event = Event::factory()->create();

    $this->assertInstanceOf(Event::class, $event);
    $this->assertNotNull($event->id);
});
