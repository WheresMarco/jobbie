<?php

declare(strict_types=1);

use App\Discovery\PlatformBoardDiscovery;
use App\Enums\BoardStatus;
use App\Enums\Platform;
use App\Enums\RemoteStatus;
use App\Jobs\ScrapeBoard;
use App\Models\Board;
use App\Models\JobPosting;
use App\Scraping\BoardScraper;
use App\Scraping\ScrapeResult;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/*
| Greenhouse, Lever and Ashby: response shapes as their public APIs
| returned them on 5 November 2026, cut down.
*/

uses(RefreshDatabase::class);

function scrapeCrawled(Board $board): ScrapeResult
{
    return app(BoardScraper::class)->scrape($board->refresh());
}

describe('Greenhouse', function (): void {
    beforeEach(function (): void {
        $this->board = Board::factory()->on(Platform::Greenhouse, 'truecaller')->create(['name' => null]);
    });

    it('lists the board, then reads each posting in Sweden', function (): void {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/v1/boards/truecaller/jobs' => Http::response(['jobs' => [
                    ['id' => 8045044, 'title' => 'Data Engineer', 'company_name' => 'Truecaller', 'location' => ['name' => 'Stockholm'], 'absolute_url' => 'https://job-boards.greenhouse.io/truecaller/jobs/8045044'],
                    ['id' => 8045045, 'title' => 'Designer', 'company_name' => 'Truecaller', 'location' => ['name' => 'Remote - Sweden']],
                    ['id' => 8280698, 'title' => 'Ads Manager', 'company_name' => 'Truecaller', 'location' => ['name' => 'Gurgaon']],
                ], 'meta' => ['total' => 3]]),
                '/v1/boards/truecaller/jobs/8045044' => Http::response([
                    'id' => 8045044,
                    'content' => '&lt;p&gt;Join &lt;strong&gt;Truecaller&lt;/strong&gt;.&lt;/p&gt;',
                    'departments' => [['id' => 1, 'name' => 'Engineering']],
                    'offices' => [['id' => 2, 'name' => 'Stockholm', 'location' => 'Stockholm, Stockholm County, Sweden']],
                    'first_published' => '2026-07-03T04:00:00-04:00',
                    'updated_at' => '2026-11-01T04:55:19-05:00',
                ]),
                // One detail fails: the posting stays, without text.
                default => Http::response('', 500),
            };
        });

        $result = scrapeCrawled($this->board);

        expect($result->jobCount)->toBe(2)
            ->and($this->board->refresh()->name)->toBe('Truecaller')
            ->and($this->board->home_url)->toBe('https://job-boards.greenhouse.io/truecaller');

        $posting = JobPosting::query()->where('feed_id', '8045044')->firstOrFail();
        expect($posting->url)->toBe('https://job-boards.greenhouse.io/truecaller/jobs/8045044')
            ->and($posting->company)->toBe('Truecaller')
            ->and($posting->cities)->toBe(['Stockholm'])
            ->and($posting->countries)->toBe(['SE'])
            ->and($posting->department)->toBe('Engineering')
            ->and($posting->posted_at?->toIso8601String())->toBe('2026-07-03T08:00:00+00:00')
            ->and($posting->description)->toBe('Join Truecaller.');

        $remote = JobPosting::query()->where('feed_id', '8045045')->firstOrFail();
        expect($remote->remote)->toBe(RemoteStatus::Fully)
            ->and($remote->countries)->toBe(['SE'])
            ->and($remote->description)->toBeNull();

        // Only the postings in Sweden were fetched one by one.
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/8280698'));
    });

    it('marks an unknown board dead', function (): void {
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(['status' => 404, 'error' => 'Job not found'], 404)]);

        expect(scrapeCrawled($this->board)->status)->toBe(BoardStatus::Dead);
    });
});

describe('Lever', function (): void {
    it('reads postings in Sweden from one request and the company from the job list', function (): void {
        $board = Board::factory()->on(Platform::Lever, 'spotify')->create(['name' => null]);
        Http::fake([
            'api.lever.co/v0/postings/spotify*' => Http::response([
                [
                    'id' => '785989a6-b219-43b1-92d9-750a67054f24',
                    'text' => 'AV Engineer',
                    'hostedUrl' => 'https://jobs.lever.co/spotify/785989a6-b219-43b1-92d9-750a67054f24',
                    'categories' => ['commitment' => 'Permanent', 'department' => 'Engineering', 'team' => 'IT', 'location' => 'Stockholm', 'allLocations' => ['Stockholm']],
                    'country' => 'SE',
                    'workplaceType' => 'onsite',
                    'createdAt' => 1791590400000,
                    'description' => '<div>Own the AV experience.</div>',
                    'lists' => [['text' => 'What You’ll Do', 'content' => '<li>Meeting rooms</li>']],
                    'additional' => '<div>Benefits.</div>',
                ],
                // Advertised in London and Stockholm: counts as Swedish.
                [
                    'id' => '11111111-2222-3333-4444-555555555555',
                    'text' => 'Backend Engineer',
                    'categories' => ['location' => 'London', 'allLocations' => ['London', 'Stockholm']],
                    'country' => 'GB',
                    'workplaceType' => 'hybrid',
                ],
                ['id' => '66666666-7777-8888-9999-000000000000', 'text' => 'Sales', 'categories' => ['location' => 'New York, NY'], 'country' => 'US'],
            ]),
            'jobs.lever.co/spotify' => Http::response('<html><head><title>Spotify</title></head></html>'),
        ]);

        expect(scrapeCrawled($board)->jobCount)->toBe(2)
            ->and($board->refresh()->name)->toBe('Spotify');

        $posting = JobPosting::query()->where('feed_id', '785989a6-b219-43b1-92d9-750a67054f24')->firstOrFail();
        expect($posting->cities)->toBe(['Stockholm'])
            ->and($posting->remote)->toBe(RemoteStatus::Onsite)
            ->and($posting->department)->toBe('Engineering')
            ->and($posting->employment_type)->toBe('Permanent')
            ->and($posting->posted_at?->toDateString())->toBe('2026-10-10')
            ->and($posting->description)->toContain('Own the AV experience.')->toContain('Meeting rooms')->toContain('Benefits.');

        $twoCountries = JobPosting::query()->where('feed_id', '11111111-2222-3333-4444-555555555555')->firstOrFail();
        expect($twoCountries->countries)->toBe(['GB', 'SE'])
            ->and($twoCountries->remote)->toBe(RemoteStatus::Hybrid)
            ->and($twoCountries->url)->toBe('https://jobs.lever.co/spotify/11111111-2222-3333-4444-555555555555');

        // The name is known now: later scrapes skip the job list.
        scrapeCrawled($board);
        Http::assertSentCount(3);
    });

    it('reads accounts in the EU region from the EU API', function (): void {
        $board = Board::factory()->on(Platform::Lever, 'voi')->create(['canonical_host' => 'jobs.eu.lever.co', 'name' => 'Voi']);
        Http::fake(['api.eu.lever.co/v0/postings/voi*' => Http::response([])]);

        expect(scrapeCrawled($board)->status)->toBe(BoardStatus::Empty);
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://api.eu.lever.co/v0/postings/voi'));
    });
});

describe('Ashby', function (): void {
    it('reads listed postings in Sweden with their addresses', function (): void {
        $board = Board::factory()->on(Platform::Ashby, 'lovable')->create(['name' => null]);
        Http::fake([
            'api.ashbyhq.com/posting-api/job-board/lovable*' => Http::response(['apiVersion' => '1', 'jobs' => [
                [
                    'id' => 'cfef6fe1-e0c9-4139-8618-b51bfb1dc683',
                    'title' => 'Data Platform Engineer',
                    'department' => 'EPD',
                    'team' => 'Data & Insights',
                    'employmentType' => 'FullTime',
                    'location' => 'Stockholm',
                    'address' => ['postalAddress' => ['addressLocality' => 'Stockholm', 'addressRegion' => 'Stockholm County', 'addressCountry' => 'Sweden']],
                    'secondaryLocations' => [],
                    'isRemote' => false,
                    'workplaceType' => 'OnSite',
                    'isListed' => true,
                    'publishedAt' => '2025-08-22T08:20:06.282+00:00',
                    'jobUrl' => 'https://jobs.ashbyhq.com/lovable/cfef6fe1-e0c9-4139-8618-b51bfb1dc683',
                    'descriptionHtml' => '<p>TL;DR - We build.</p>',
                    'shouldDisplayCompensationOnJobPostings' => true,
                    'compensation' => ['compensationTierSummary' => '60K – 80K SEK'],
                ],
                ['id' => 'a0000000-0000-0000-0000-000000000000', 'title' => 'Unlisted', 'location' => 'Stockholm', 'isListed' => false],
                [
                    'id' => 'b0000000-0000-0000-0000-000000000000',
                    'title' => 'Engineer, Remote',
                    'location' => 'Remote',
                    'workplaceType' => 'Remote',
                    'secondaryLocations' => [['location' => 'Göteborg', 'address' => ['postalAddress' => ['addressCountry' => 'Sweden', 'addressLocality' => 'Göteborg']]]],
                ],
                // One posting per country, with the country as the locality too.
                ['id' => 'd0000000-0000-0000-0000-000000000000', 'title' => 'Engineering Lead', 'location' => 'Sweden', 'address' => ['postalAddress' => ['addressCountry' => 'Sweden', 'addressLocality' => 'Sweden']]],
                ['id' => 'c0000000-0000-0000-0000-000000000000', 'title' => 'Berlin office', 'location' => 'Berlin', 'address' => ['postalAddress' => ['addressCountry' => 'Germany']]],
            ]]),
            'jobs.ashbyhq.com/lovable' => Http::response('<html><title>Lovable Jobs</title></html>'),
        ]);

        expect(scrapeCrawled($board)->jobCount)->toBe(3)
            ->and($board->refresh()->name)->toBe('Lovable');

        expect(JobPosting::query()->where('feed_id', 'd0000000-0000-0000-0000-000000000000')->firstOrFail())
            ->cities->toBe([])
            ->countries->toBe(['SE']);

        $posting = JobPosting::query()->where('feed_id', 'cfef6fe1-e0c9-4139-8618-b51bfb1dc683')->firstOrFail();
        expect($posting->cities)->toBe(['Stockholm'])
            ->and($posting->countries)->toBe(['SE'])
            ->and($posting->remote)->toBe(RemoteStatus::Onsite)
            ->and($posting->employment_type)->toBe('Full-time')
            ->and($posting->salary_text)->toBe('60K – 80K SEK')
            ->and($posting->department)->toBe('EPD')
            ->and($posting->description)->toBe('TL;DR - We build.');

        expect(JobPosting::query()->where('feed_id', 'b0000000-0000-0000-0000-000000000000')->firstOrFail())
            ->remote->toBe(RemoteStatus::Fully)
            ->cities->toBe(['Göteborg']);
    });
});

it('creates boards for the accounts that Common Crawl has links to', function (): void {
    Board::factory()->on(Platform::Lever, 'spotify')->create();
    Http::fake(function (Request $request) {
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $pattern = $query['url'] ?? '';

        return match (true) {
            str_contains($url, 'collinfo.json') => Http::response([['id' => 'CC-MAIN-2026-43', 'cdx-api' => 'https://index.commoncrawl.org/CC-MAIN-2026-43-index']]),
            isset($query['showNumPages']) => Http::response(['pages' => $pattern === 'jobs.lever.co/*' ? 1 : 0]),
            default => Http::response(implode("\n", [
                '{"url": "https://jobs.lever.co/spotify/785989a6-b219-43b1-92d9-750a67054f24"}',
                '{"url": "https://jobs.lever.co/Klarna/"}',
                '{"url": "https://jobs.lever.co/klarna/1234/apply"}',
                '{"url": "https://jobs.lever.co/robots.txt"}',
            ])),
        };
    });

    $counts = app(PlatformBoardDiscovery::class)->crawl(Platform::Lever);

    expect($counts)->toBe(['lever' => ['accounts' => 2, 'created' => 1]])
        ->and(Board::query()->where('platform', Platform::Lever)->orderBy('external_key')->pluck('external_key')->all())->toBe(['Klarna', 'spotify'])
        ->and(app(PlatformBoardDiscovery::class)->crawlable())->toBe([Platform::Greenhouse, Platform::Lever, Platform::Ashby]);
});

it('rechecks empty boards on worldwide platforms weekly, and other empty boards nightly', function (): void {
    Bus::fake();
    $this->travelTo('2026-11-05 03:00:00');
    Board::factory()->on(Platform::Lever, 'recent')->create(['status' => BoardStatus::Empty, 'checked_at' => now()->subDays(2)]);
    Board::factory()->on(Platform::Lever, 'stale')->create(['status' => BoardStatus::Empty, 'checked_at' => now()->subDays(8)]);
    Board::factory()->on(Platform::Lever, 'active')->create(['status' => BoardStatus::Active, 'checked_at' => now()->subDay()]);
    Board::factory()->custom('career.example.se')->create(['status' => BoardStatus::Empty, 'checked_at' => now()->subDays(2)]);

    $this->artisan('radar:scrape', ['--no-wait' => true])->assertSuccessful();

    Bus::assertBatched(function (PendingBatch $batch): bool {
        $keys = $batch->jobs->map(fn (ScrapeBoard $job): string => $job->board->external_key)->sort()->values()->all();

        return $keys === ['active', 'career.example.se', 'stale'];
    });
});

it('scrapes a few boards at once on a shared API host', function (): void {
    $boards = Board::factory()->count(5)->sequence(fn ($s) => ['external_key' => "company{$s->index}"])->on(Platform::Lever)->create();
    $keys = $boards->map(fn (Board $b): string => (new ScrapeBoard($b))->middleware()[0]->key)->unique()->sort()->values()->all();

    expect($keys)->toHaveCount(4)
        ->and($keys[0])->toStartWith('host:jobs.lever.co:')
        ->and((new ScrapeBoard(Board::factory()->custom('career.example.se')->create()))->middleware()[0]->key)->toBe('host:career.example.se');
});
