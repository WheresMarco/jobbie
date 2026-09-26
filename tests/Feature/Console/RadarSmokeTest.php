<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TeamtailorFeed;

it('verifies and reads real sites without touching the database', function (): void {
    TeamtailorFeed::fake('career.kry.se', TeamtailorFeed::items(3), ['1000' => ['department' => 'Vård']]);

    $this->artisan('radar:smoke', ['hosts' => ['career.kry.se']])
        ->expectsTable(['Värd', 'Steg', 'Orsak', 'Slutvärd', 'Annonser', 'RSS-poster'], [
            ['career.kry.se', 'verified', 'cname:ext', 'career.kry.se', '3', '1'],
        ])
        ->assertSuccessful();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'jobs.rss'));
});

it('fails when a site cannot be verified', function (): void {
    Http::fake(['*' => Http::response('Not found', 404)]);

    $this->artisan('radar:smoke', ['hosts' => ['careers.einride.tech']])->assertFailed();
});
