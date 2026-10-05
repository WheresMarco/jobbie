<?php

declare(strict_types=1);

use App\Enums\BoardStatus;
use App\Enums\RemoteStatus;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\JobPostingLocation;
use App\Scraping\BoardScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\TeamtailorFeed;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->board = Board::factory()->custom('career.example.se')->create(['name' => null]);
});

function scrape(Board $board): App\Scraping\ScrapeResult
{
    return app(BoardScraper::class)->scrape($board->refresh());
}

/**
 * @return array<string, array{department: string, remote: string}>
 */
function rssFor(int $count, int $firstId = 1000): array
{
    $rss = [];
    foreach (range(0, $count - 1) as $i) {
        $rss[(string) ($firstId + $i)] = ['department' => $i % 2 === 0 ? 'Teknik' : 'Sälj', 'remote' => $i % 3 === 0 ? 'hybrid' : 'onsite'];
    }

    return $rss;
}

it('stores 150 postings over two pages with RSS enrichment via offset', function (): void {
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(150), rssFor(150));

    $result = scrape($this->board);

    expect($result->status)->toBe(BoardStatus::Active)
        ->and($result->jobCount)->toBe(150)
        ->and($result->truncated)->toBeFalse()
        ->and(JobPosting::query()->count())->toBe(150)
        ->and(JobPostingLocation::query()->count())->toBe(150);

    $posting = JobPosting::query()->where('feed_id', '1149')->firstOrFail();
    expect($posting->department)->toBe('Sälj')
        ->and($posting->remote)->toBe(RemoteStatus::Onsite)
        ->and($posting->cities)->toBe(['Stockholm'])
        ->and($posting->countries)->toBe(['SE'])
        ->and($posting->company)->toBe('Exempel AB')
        ->and($posting->active)->toBeTrue()
        ->and(JobPosting::query()->where('feed_id', '1000')->value('remote'))->toBe(RemoteStatus::Hybrid);

    $this->board->refresh();
    expect($this->board->status)->toBe(BoardStatus::Active)
        ->and($this->board->job_count)->toBe(150)
        ->and($this->board->name)->toBe('Exempel AB')
        ->and($this->board->home_url)->toBe('https://career.example.se/')
        ->and($this->board->checked_at)->not->toBeNull()
        ->and($this->board->error)->toBeNull();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'jobs.rss?offset=100'));
});

it('updates postings, keeps first_seen_at and deactivates vanished ones', function (): void {
    Carbon::setTestNow('2026-09-01 10:00:00');
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(120));
    scrape($this->board);

    Carbon::setTestNow('2026-09-02 10:00:00');
    $items = array_slice(TeamtailorFeed::items(120), 10);
    $items[0]['title'] = 'Ny titel';
    TeamtailorFeed::fake('career.example.se', $items);
    $result = scrape($this->board);

    $updated = JobPosting::query()->where('feed_id', (string) $items[0]['id'])->firstOrFail();

    expect($result->deactivated)->toBe(10)
        ->and($result->jobCount)->toBe(110)
        ->and(JobPosting::query()->where('active', false)->count())->toBe(10)
        ->and($updated->title)->toBe('Ny titel')
        ->and($updated->first_seen_at->toDateString())->toBe('2026-09-01')
        ->and($updated->last_seen_at->toDateString())->toBe('2026-09-02')
        ->and(JobPostingLocation::query()->count())->toBe(120);
});

it('reactivates postings that come back', function (): void {
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(3));
    scrape($this->board);
    TeamtailorFeed::fake('career.example.se', array_slice(TeamtailorFeed::items(3), 1));
    scrape($this->board);
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(3));
    scrape($this->board);

    expect(JobPosting::query()->where('active', true)->count())->toBe(3);
});

it('does not deactivate anything when the read was truncated', function (): void {
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(150));
    scrape($this->board);

    // Paging now repeats page one forever.
    TeamtailorFeed::reset();
    Http::fake(['career.example.se/jobs.json*' => Http::response(TeamtailorFeed::page(TeamtailorFeed::items(100)))]);
    Http::fake(['career.example.se/jobs.rss*' => Http::response(TeamtailorFeed::rss([]))]);
    $result = scrape($this->board);

    expect($result->truncated)->toBeTrue()
        ->and($result->deactivated)->toBe(0)
        ->and(JobPosting::query()->where('active', true)->count())->toBe(150);
});

it('saves postings without department and remote status when RSS fails', function (): void {
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(5), override: fn (Request $r) => str_contains($r->url(), 'jobs.rss')
        ? Http::response('boom', 500)
        : null);

    $result = scrape($this->board);

    expect($result->status)->toBe(BoardStatus::Active)
        ->and(JobPosting::query()->count())->toBe(5)
        ->and(JobPosting::query()->whereNotNull('department')->count())->toBe(0)
        ->and(JobPosting::query()->whereNotNull('remote')->count())->toBe(0);
});

it('marks a gone site dead and closes its postings', function (int $status): void {
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(5));
    scrape($this->board);
    TeamtailorFeed::reset();
    Http::fake(['career.example.se/*' => Http::response('Gone', $status)]);

    $result = scrape($this->board);

    expect($result->status)->toBe(BoardStatus::Dead)
        ->and($result->deactivated)->toBe(5)
        ->and($this->board->refresh()->status)->toBe(BoardStatus::Dead)
        ->and($this->board->job_count)->toBe(0)
        ->and($this->board->error)->toBe("HTTP {$status}")
        ->and(JobPosting::query()->where('active', true)->count())->toBe(0);
})->with([404, 410]);

it('marks a site serving HTML instead of a feed dead', function (): void {
    Http::fake(['career.example.se/*' => Http::response('<!DOCTYPE html><html><body>Välkommen</body></html>', 200, ['Content-Type' => 'text/html'])]);

    expect(scrape($this->board)->status)->toBe(BoardStatus::Dead)
        ->and($this->board->refresh()->error)->toBe('Not a JSON feed');
});

it('keeps the last known postings on temporary errors', function (): void {
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(5));
    scrape($this->board);
    TeamtailorFeed::reset();
    Http::fake(['career.example.se/*' => Http::response('boom', 502)]);

    $result = scrape($this->board);

    expect($result->status)->toBe(BoardStatus::Error)
        ->and($this->board->refresh()->job_count)->toBe(5)
        ->and($this->board->error)->toBe('HTTP 502')
        ->and(JobPosting::query()->where('active', true)->count())->toBe(5);
});

it('marks a board without postings empty', function (): void {
    TeamtailorFeed::fake('career.example.se', []);

    expect(scrape($this->board)->status)->toBe(BoardStatus::Empty)
        ->and($this->board->refresh()->job_count)->toBe(0);
});

it('stores every location so postings can be filtered by country', function (): void {
    $items = [
        TeamtailorFeed::item(1),
        TeamtailorFeed::item(2, ['_jobposting' => ['jobLocation' => [
            ['address' => ['addressLocality' => 'Oslo', 'addressCountry' => 'Norway']],
            ['address' => ['addressLocality' => 'Bergen', 'addressCountry' => 'Norge']],
        ]]]),
        TeamtailorFeed::item(3, ['_jobposting' => ['jobLocation' => [
            ['address' => ['addressLocality' => 'Malmö', 'addressCountry' => 'Sverige']],
            ['address' => ['addressLocality' => 'København', 'addressCountry' => 'Denmark']],
        ]]]),
    ];
    TeamtailorFeed::fake('career.example.se', $items);
    scrape($this->board);

    $inCountry = fn (string $code): array => JobPosting::query()
        ->whereHas('locations', fn ($q) => $q->where('country', $code))
        ->orderBy('feed_id')
        ->pluck('feed_id')
        ->all();

    expect($inCountry('SE'))->toBe(['1', '3'])
        ->and($inCountry('NO'))->toBe(['2'])
        ->and($inCountry('DK'))->toBe(['3'])
        ->and(JobPosting::query()->where('feed_id', '2')->firstOrFail()->cities)->toBe(['Oslo', 'Bergen']);
});

it('follows a slug that redirects to a custom domain for paging and RSS', function (): void {
    $slug = Board::factory()->create(['canonical_host' => 'kry.teamtailor.com', 'tt_slug' => 'kry']);
    TeamtailorFeed::fake('career.kry.se', TeamtailorFeed::items(150), ['1149' => ['department' => 'Vård']], override: fn (Request $r) => str_starts_with($r->url(), 'https://kry.teamtailor.com/')
        ? Http::response('', 301, ['Location' => 'https://career.kry.se/jobs.json?page=1'])
        : null);

    $result = scrape($slug);

    expect($result->listing?->finalHost)->toBe('career.kry.se')
        ->and($result->listing?->homeUrl)->toBe('https://career.kry.se/')
        ->and($result->jobCount)->toBe(150)
        ->and(JobPosting::query()->where('feed_id', '1149')->value('department'))->toBe('Vård');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://career.kry.se/jobs.json?page=2');
    Http::assertNotSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://kry.teamtailor.com/jobs.rss'));
});
