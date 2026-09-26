<?php

declare(strict_types=1);

use App\Enums\RemoteStatus;
use App\Scraping\RssEnricher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\TeamtailorFeed;

function enricher(): RssEnricher
{
    return new RssEnricher('RadarTest/1.0', 5);
}

it('parses namespaced department and remote status', function (): void {
    $items = RssEnricher::parse(TeamtailorFeed::rss([
        '1' => ['department' => 'Teknik & IT', 'remote' => 'hybrid'],
        '2' => ['remote' => 'FULLY'],
        '3' => ['remote' => 'sometimes'],
        '4' => [],
    ]));

    expect($items['1']->department)->toBe('Teknik & IT')
        ->and($items['1']->remote)->toBe(RemoteStatus::Hybrid)
        ->and($items['2']->remote)->toBe(RemoteStatus::Fully)
        ->and($items['3']->remote)->toBeNull()
        ->and($items['4']->department)->toBeNull();
});

it('pages through the feed with offset until no new guid appears', function (): void {
    $rss = [];
    foreach (range(1, 150) as $i) {
        $rss[(string) $i] = ['department' => "Avdelning {$i}", 'remote' => 'onsite'];
    }
    TeamtailorFeed::fake('career.example.se', [], $rss);

    $result = enricher()->fetch('career.example.se');

    expect($result)->toHaveCount(150)
        ->and($result['150']->department)->toBe('Avdelning 150');

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'jobs.rss?offset=100'));
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'offset=200'));
});

it('stops when a full page repeats the same guids', function (): void {
    $page = [];
    foreach (range(1, 100) as $i) {
        $page[(string) $i] = ['department' => 'Samma'];
    }
    Http::fake(['career.example.se/jobs.rss*' => Http::response(TeamtailorFeed::rss($page))]);

    expect(enricher()->fetch('career.example.se'))->toHaveCount(100);
    Http::assertSentCount(2);
});

it('never throws and keeps what it has on errors', function (): void {
    Log::spy();
    $first = [];
    foreach (range(1, 100) as $i) {
        $first[(string) $i] = ['department' => 'Teknik'];
    }
    Http::fakeSequence('career.example.se/jobs.rss*')
        ->push(TeamtailorFeed::rss($first))
        ->push('boom', 500)
        ->push('boom', 500);

    expect(enricher()->fetch('career.example.se'))->toHaveCount(100);
    Log::shouldHaveReceived('warning')->once();
});

it('returns nothing for invalid XML', function (): void {
    Http::fake(['career.example.se/jobs.rss*' => Http::response('<html><body>Hej')]);

    expect(enricher()->fetch('career.example.se'))->toBe([]);
});
