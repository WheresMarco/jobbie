<?php

declare(strict_types=1);

use App\Enums\BoardStatus;
use App\Enums\Platform;
use App\Enums\RemoteStatus;
use App\Models\Board;
use App\Models\JobPosting;
use App\Scraping\BoardScraper;
use App\Scraping\ScrapeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PlatformFeeds;

uses(RefreshDatabase::class);

function scrapeBoard(Board $board): ScrapeResult
{
    return app(BoardScraper::class)->scrape($board->refresh());
}

describe('Varbi', function (): void {
    beforeEach(function (): void {
        $this->board = Board::factory()->on(Platform::Varbi, 'gotene')->create(['name' => null]);
    });

    it('reads every job in the RSS feed', function (): void {
        Http::fake(['gotene.varbi.com/what:rssfeed/' => Http::response(
            PlatformFeeds::varbiRss('gotene', 'Götene kommun', [983696 => 'Samordnare', 970471 => 'Undersköterska']),
            200,
            ['Content-Type' => 'application/rss+xml'],
        )]);

        $result = scrapeBoard($this->board);

        expect($result->status)->toBe(BoardStatus::Active)
            ->and($result->jobCount)->toBe(2)
            ->and($this->board->refresh()->name)->toBe('Götene kommun');

        $posting = JobPosting::query()->where('feed_id', '983696')->firstOrFail();
        expect($posting->title)->toBe('Samordnare')
            ->and($posting->url)->toBe('https://gotene.varbi.com/se/what:job/jobID:983696/')
            ->and($posting->company)->toBe('Götene kommun')
            ->and($posting->cities)->toBe([])
            ->and($posting->description)->toStartWith("Samordnare\n\nVi söker dig")
            ->and($posting->posted_at?->toIso8601String())->toBe('2026-11-02T23:00:00+00:00');
    });

    it('closes postings that leave the feed and marks a missing feed dead', function (): void {
        Http::fake(['gotene.varbi.com/what:rssfeed/' => Http::sequence()
            ->push(PlatformFeeds::varbiRss('gotene', 'Götene kommun', [1 => 'A', 2 => 'B']))
            ->push(PlatformFeeds::varbiRss('gotene', 'Götene kommun', [2 => 'B']))
            ->push('Not found', 404)]);

        scrapeBoard($this->board);
        expect(scrapeBoard($this->board)->deactivated)->toBe(1);

        expect(scrapeBoard($this->board)->status)->toBe(BoardStatus::Dead)
            ->and(JobPosting::query()->where('active', true)->count())->toBe(0);
    });

    it('treats an HTML page as a dead feed', function (): void {
        Http::fake(['gotene.varbi.com/*' => Http::response('<!DOCTYPE html><html><body>Hej</body></html>')]);

        expect(scrapeBoard($this->board)->status)->toBe(BoardStatus::Dead);
    });
});

describe('SmartRecruiters', function (): void {
    beforeEach(function (): void {
        $this->board = Board::factory()->on(Platform::SmartRecruiters, 'Vattenfall')->create(['name' => null]);
    });

    it('pages through Swedish postings and reads each ad', function (): void {
        $items = array_map(static fn (int $i): array => PlatformFeeds::smartRecruitersItem((string) (744000150000000 + $i), 'Vattenfall'), range(0, 104));
        // The API filters on country, but anything else that slips through is dropped.
        $items[3]['location']['country'] = 'de';

        Http::fake(function (Request $request) use ($items) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($path === '/v1/companies/Vattenfall/postings') {
                expect($query['country'])->toBe('se');

                return Http::response(['offset' => (int) $query['offset'], 'limit' => 100, 'totalFound' => count($items), 'content' => array_slice($items, (int) $query['offset'], 100)]);
            }

            $id = basename($path);
            $item = collect($items)->firstWhere('id', $id);

            // One detail request fails: the posting is kept without its text.
            return $id === '744000150000007' || $item === null ? Http::response('', 500) : Http::response(PlatformFeeds::smartRecruitersDetail($item));
        });

        $result = scrapeBoard($this->board);

        expect($result->jobCount)->toBe(104)
            ->and($result->truncated)->toBeFalse()
            ->and($this->board->refresh()->name)->toBe('Vattenfall AB')
            ->and($this->board->home_url)->toBe('https://jobs.smartrecruiters.com/Vattenfall')
            ->and(JobPosting::query()->where('feed_id', '744000150000003')->exists())->toBeFalse();

        $posting = JobPosting::query()->where('feed_id', '744000150000000')->firstOrFail();
        expect($posting->url)->toBe('https://jobs.smartrecruiters.com/Vattenfall/744000150000000-ingenjor')
            ->and($posting->cities)->toBe(['Stockholm'])
            ->and($posting->countries)->toBe(['SE'])
            ->and($posting->remote)->toBe(RemoteStatus::Hybrid)
            ->and($posting->department)->toBe('Engineering')
            ->and($posting->employment_type)->toBe('Full-time')
            ->and($posting->description)->toBe("Du bygger saker.\n\nErfarenhet\n\nEtt bolag.");

        expect(JobPosting::query()->where('feed_id', '744000150000007')->firstOrFail())
            ->description->toBeNull()
            ->url->toBe('https://jobs.smartrecruiters.com/Vattenfall/744000150000007');
    });

    it('marks an unknown company dead', function (): void {
        Http::fake(['api.smartrecruiters.com/*' => Http::response(['message' => 'Not found'], 404)]);

        expect(scrapeBoard($this->board)->status)->toBe(BoardStatus::Dead);
    });
});

describe('Workday', function (): void {
    beforeEach(function (): void {
        $this->host = 'exempel.wd3.myworkdayjobs.com';
        $this->board = Board::factory()->on(Platform::Workday, "{$this->host}/Careers")->create(['name' => null]);
    });

    it('narrows the list to Sweden with the country facet and reads each posting', function (): void {
        $postings = array_map(static fn (int $i): array => [
            'path' => "/job/Vasteras-Sweden/Ingenjor-{$i}_R{$i}",
            'title' => "Ingenjör {$i}",
            'req' => "R{$i}",
        ], range(1, 25));

        $requests = [];
        Http::fake(function (Request $request) use ($postings, &$requests) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/wday/cxs/exempel/Careers/jobs') {
                $requests[] = $request->data();
                $applied = (array) ($request->data()['appliedFacets'] ?? []);
                $offset = (int) $request->data()['offset'];

                // Unfiltered, the site has postings all over the world.
                return Http::response($applied === []
                    ? PlatformFeeds::workdayList([], 900)
                    : PlatformFeeds::workdayList(array_slice($postings, $offset, 20), $offset === 0 ? 25 : 0));
            }

            preg_match('~_(R\d+)$~', $path, $m);
            $overrides = match ($m[1]) {
                'R2' => ['country' => ['descriptor' => 'Norway', 'id' => 'no-id'], 'location' => 'Oslo, Oslo, Norway', 'additionalLocations' => ['Remote - Norway']],
                // Advertised for Poland, with Sweden as an extra place.
                'R3' => ['country' => ['descriptor' => 'Poland', 'id' => 'pl-id'], 'location' => 'Krakow, Lesser Poland, Poland', 'additionalLocations' => ['Remote - Sweden', 'Remote - London, United Kingdom']],
                default => [],
            };

            return Http::response(PlatformFeeds::workdayDetail($this->host, 'Careers', substr($path, strlen('/wday/cxs/exempel/Careers')), $m[1], $overrides));
        });

        $result = scrapeBoard($this->board);

        expect($requests)->toHaveCount(3)
            // Sent as a JSON object, also when empty.
            ->and($requests[0]['appliedFacets'])->toEqual(new stdClass())
            ->and((array) $requests[1]['appliedFacets'])->toBe(['locationCountry' => ['se-id']])
            ->and($requests[2]['offset'])->toBe(20)
            // R2 turned out to be in Norway after all.
            ->and($result->jobCount)->toBe(24)
            ->and($this->board->refresh()->name)->toBe('Exempel Sweden AB');

        $posting = JobPosting::query()->where('feed_id', 'R1')->firstOrFail();
        expect($posting->url)->toBe("https://{$this->host}/Careers/job/Vasteras-Sweden/Ingenjor-1_R1")
            ->and($posting->title)->toBe('Processingenjör')
            ->and($posting->cities)->toBe(['Västerås', 'Ludvika'])
            ->and($posting->countries)->toBe(['SE'])
            ->and($posting->remote)->toBe(RemoteStatus::Hybrid)
            ->and($posting->employment_type)->toBe('Full time')
            ->and($posting->posted_at?->toDateString())->toBe('2026-11-02')
            ->and($posting->description)->toBe('Du utvecklar processen.');

        expect(JobPosting::query()->where('feed_id', 'R3')->firstOrFail())
            ->cities->toBe(['Krakow', 'London'])
            ->countries->toBe(['PL', 'SE', 'GB']);
    });

    it('stops when the site has no postings in Sweden', function (): void {
        Http::fake(['*/jobs' => Http::response(PlatformFeeds::workdayList([], 300, ['no-id' => 'Norway']))]);

        $result = scrapeBoard($this->board);

        expect($result->status)->toBe(BoardStatus::Empty)
            ->and($result->truncated)->toBeFalse();
        Http::assertSentCount(1);
    });

    it('filters on each posting when the site has no country facet', function (): void {
        $postings = [
            ['path' => '/job/Stockholm/A_R1', 'title' => 'A', 'req' => 'R1'],
            ['path' => '/job/Oslo/B_R2', 'title' => 'B', 'req' => 'R2'],
        ];

        Http::fake(function (Request $request) use ($postings) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/jobs')) {
                return Http::response(PlatformFeeds::workdayList($postings, 2, null));
            }

            return str_contains($path, 'Oslo')
                ? Http::response(PlatformFeeds::workdayDetail($this->host, 'Careers', '/job/Oslo/B_R2', 'R2', ['country' => ['descriptor' => 'Norway', 'id' => 'x'], 'location' => 'Oslo, Norway', 'additionalLocations' => []]))
                // Without a detail the country is unknown, so the posting is dropped.
                : Http::response('', 500);
        });

        expect(scrapeBoard($this->board)->jobCount)->toBe(0);
    });
});
