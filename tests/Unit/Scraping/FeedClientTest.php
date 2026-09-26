<?php

declare(strict_types=1);

use App\Scraping\FeedClient;
use App\Scraping\FeedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TeamtailorFeed;

function feedClient(): FeedClient
{
    return new FeedClient('RadarTest/1.0', 5);
}

it('reads 150 postings over two pages', function (): void {
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(150));

    $feed = feedClient()->fetch('career.example.se');

    expect($feed->items)->toHaveCount(150)
        ->and($feed->pages)->toBe(2)
        ->and($feed->truncated)->toBeFalse()
        ->and($feed->title)->toBe('Exempel AB')
        ->and($feed->homePageUrl)->toBe('https://career.example.se/')
        ->and($feed->finalHost())->toBe('career.example.se');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://career.example.se/jobs.json?page=2'
        && $r->hasHeader('User-Agent', 'RadarTest/1.0'));
});

it('stops after an exactly full last page', function (): void {
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(100));

    $feed = feedClient()->fetch('career.example.se');

    expect($feed->items)->toHaveCount(100)
        ->and($feed->truncated)->toBeFalse()
        ->and($feed->pages)->toBe(2);
});

it('marks the feed truncated when a full page brings no new ids', function (): void {
    $items = TeamtailorFeed::items(100);
    Http::fake(['career.example.se/jobs.json*' => Http::response(TeamtailorFeed::page($items))]);

    $feed = feedClient()->fetch('career.example.se');

    expect($feed->items)->toHaveCount(100)
        ->and($feed->truncated)->toBeTrue();
});

it('marks the feed truncated when a later page fails', function (): void {
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(250), override: fn (Request $r) => str_contains($r->url(), 'page=2')
        ? Http::response('boom', 500)
        : null);

    $feed = feedClient()->fetch('career.example.se');

    expect($feed->items)->toHaveCount(100)
        ->and($feed->truncated)->toBeTrue();
});

it('stops at 100 pages', function (): void {
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(10_050));

    $feed = feedClient()->fetch('career.example.se');

    expect($feed->pages)->toBe(FeedClient::MAX_PAGES)
        ->and($feed->items)->toHaveCount(10_000)
        ->and($feed->truncated)->toBeTrue();
});

it('treats gone sites and non-feeds as dead', function (int $status, string $body): void {
    Http::fake(['career.example.se/*' => Http::response($body, $status)]);

    try {
        feedClient()->fetch('career.example.se');
        $this->fail('Expected FeedException');
    } catch (FeedException $e) {
        expect($e->dead)->toBeTrue();
    }
})->with([
    '404' => [404, 'Not found'],
    '410' => [410, 'Gone'],
    'HTML with 200' => [200, '<!DOCTYPE html><html><body>Karriär</body></html>'],
    'other JSON' => [200, '{"jobs": []}'],
    'feed without job postings' => [200, '{"version": "https://jsonfeed.org/version/1.1", "items": [{"id": "1"}]}'],
]);

it('treats server and connection errors as temporary', function (): void {
    Http::fake(['career.example.se/*' => Http::response('boom', 503)]);

    try {
        feedClient()->fetch('career.example.se');
        $this->fail('Expected FeedException');
    } catch (FeedException $e) {
        expect($e->dead)->toBeFalse()
            ->and($e->getMessage())->toBe('HTTP 503');
    }

    Http::assertSentCount(3);
});

it('reports connection failures as temporary', function (): void {
    Http::fake(['career.example.se/*' => Http::failedConnection()]);

    expect(fn () => feedClient()->fetch('career.example.se'))
        ->toThrow(fn (FeedException $e) => expect($e->dead)->toBeFalse());
});

it('accepts an empty feed', function (): void {
    TeamtailorFeed::fake('career.example.se', []);

    expect(feedClient()->fetch('career.example.se')->items)->toBe([]);
});
