<?php

declare(strict_types=1);

use App\Discovery\JobStream\JobStreamSync;
use App\Discovery\Sources\WaybackSource;
use App\Discovery\WaybackIndex;
use App\Discovery\WaybackUnavailable;
use App\Enums\CandidateSourceType;
use App\Enums\Platform;
use App\Enums\Strength;
use App\Jobs\CollectCandidates;
use App\Jobs\CollectWaybackCandidates;
use App\Jobs\CrawlPlatformBoards;
use App\Jobs\DiscoverPlatformBoards;
use App\Jobs\QueueVerification;
use App\Jobs\SyncJobStream;
use App\Models\Board;
use App\Models\Candidate;
use App\Models\Domain;
use App\Models\PlatsbankenAd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function platsbankenAd(string $id, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $id,
        'headline' => "Sjuksköterska  {$id}",
        'publication_date' => '2026-09-20T08:00:00',
        'employer' => [
            'name' => 'Kry International AB',
            'organization_number' => '556986-9383',
            'url' => 'https://www.kry.se/om-oss',
        ],
        'application_details' => ['url' => "https://career.kry.se/jobs/{$id}-sjukskoterska"],
        'webpage_url' => "https://arbetsformedlingen.se/platsbanken/annonser/{$id}",
    ], $overrides);
}

it('collects prefix candidates for known domains and custom boards', function (): void {
    config(['radar.prefixes' => ['career', 'jobb']]);
    Domain::factory()->create(['base_domain' => 'kry.se']);
    Board::factory()->custom('karriar.timefold.ai')->create();

    $this->artisan('radar:discover', ['--source' => ['prefixes'], '--sync' => true])
        ->expectsOutputToContain('4 nya kandidater')
        ->assertSuccessful();

    expect(Candidate::query()->orderBy('host')->pluck('host')->all())
        ->toBe(['career.kry.se', 'career.timefold.ai', 'jobb.kry.se', 'jobb.timefold.ai']);

    $candidate = Candidate::query()->findOrFail('jobb.kry.se');
    expect($candidate->source)->toBe(CandidateSourceType::Prefix)
        ->and($candidate->strength)->toBe(Strength::Weak)
        ->and($candidate->source_ref)->toBe('jobb')
        ->and(Domain::query()->findOrFail('kry.se')->probed_at)->not->toBeNull();
});

it('imports base domains from a CSV file', function (): void {
    config(['radar.prefixes' => ['jobb']]);
    $csv = tempnam(sys_get_temp_dir(), 'domains');
    file_put_contents($csv, "domain,org_nr,name\nwww.Kry.se,556986-9383,Kry\nhttps://jobs.foretag.co.uk/x,,\nnot a domain,,\n");

    $this->artisan('radar:discover', ['--source' => ['prefixes'], '--domains' => $csv, '--sync' => true])
        ->expectsOutputToContain('2 nya domäner importerades')
        ->assertSuccessful();

    expect(Domain::query()->findOrFail('kry.se'))
        ->org_nr->toBe('5569869383')
        ->company_name->toBe('Kry')
        ->source->toBe(CandidateSourceType::Seed)
        ->and(Candidate::query()->pluck('host')->sort()->values()->all())->toBe(['jobb.foretag.co.uk', 'jobb.kry.se']);

    @unlink($csv);
});

it('collects slug hosts from the latest Common Crawl indexes', function (): void {
    config(['radar.commoncrawl.index_count' => 2]);
    Http::fake(function (Request $request) {
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return match (true) {
            str_contains($url, 'collinfo.json') => Http::response([
                ['id' => 'CC-MAIN-2026-35', 'cdx-api' => 'https://index.commoncrawl.org/CC-MAIN-2026-35-index'],
                ['id' => 'CC-MAIN-2026-30', 'cdx-api' => 'https://index.commoncrawl.org/CC-MAIN-2026-30-index'],
                ['id' => 'CC-MAIN-2026-25', 'cdx-api' => 'https://index.commoncrawl.org/CC-MAIN-2026-25-index'],
            ]),
            isset($query['showNumPages']) => Http::response(['pages' => str_contains($url, '2026-35') ? 2 : 1, 'pageSize' => 5, 'blocks' => 7]),
            str_contains($url, '2026-35') && ($query['page'] ?? null) === '0' => Http::response(
                "{\"url\": \"https://kry.teamtailor.com/jobs/1-a\"}\n{\"url\": \"https://kry.teamtailor.com/jobs/2-b\"}\n{\"url\": \"https://www.teamtailor.com/\"}\n",
            ),
            str_contains($url, '2026-35') => Http::response("{\"url\": \"https://acme.teamtailor.com/\"}\n"),
            str_contains($url, '2026-30') => Http::response("{\"url\": \"https://kry.teamtailor.com/\"}\n{\"url\": \"https://beta.teamtailor.com/\"}\n"),
            default => Http::response('unexpected', 500),
        };
    });

    $this->artisan('radar:discover', ['--source' => ['commoncrawl'], '--sync' => true])->assertSuccessful();

    expect(Candidate::query()->orderBy('host')->pluck('host')->all())->toBe(['acme.teamtailor.com', 'beta.teamtailor.com', 'kry.teamtailor.com'])
        ->and(Candidate::query()->findOrFail('kry.teamtailor.com')->source_ref)->toBe('CC-MAIN-2026-35')
        ->and(Candidate::query()->findOrFail('kry.teamtailor.com')->strength)->toBe(Strength::Strong);

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '2026-25'));
});

/**
 * The Wayback CDX API over a sorted list of URL keys, read from the resume
 * key onwards like the real one. $before runs ahead of each answer and may
 * replace it.
 *
 * @param  list<string>  $keys
 */
function fakeWayback(array $keys, ?Closure $before = null): void
{
    sort($keys);
    app()->bind(WaybackSource::class, fn (): WaybackSource => new WaybackSource(app(WaybackIndex::class), slice: 3));

    Http::fake(function (Request $request) use ($keys, $before) {
        if (! str_starts_with($request->url(), WaybackIndex::CDX_URL)) {
            return Http::response('unexpected', 500);
        }
        if ($before !== null && ($response = $before()) !== null) {
            return $response;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $from = isset($query['resumeKey']) ? strstr((string) gzuncompress((string) base64_decode(strtr($query['resumeKey'], '-_', '+/'))), ' ', true) : null;
        $slice = array_slice(array_values(array_filter($keys, fn (string $key): bool => $from === null || strcmp($key, $from) > 0)), 0, (int) $query['limit']);

        return Http::response(implode("\n", $slice)."\n\nresumekey\n");
    });
}

/** @return list<string> */
function waybackKeys(): array
{
    return [
        'com,teamtailor)/', 'com,teamtailor)/en/pricing', 'com,teamtailor,app)/login',
        ...array_map(fn (int $i): string => "com,teamtailor,cdn,media)/{$i}.png", range(1, 9)),
        'com,teamtailor,delorean)/connect', 'com,teamtailor,delorean)/jobs', 'com,teamtailor,delorean-group)/',
        'com,teamtailor,kry:443)/', 'com,teamtailor,www)/en', 'com,teamtailor,www)/sv', 'com,teamtailor,zenjob)/',
    ];
}

it('walks the Internet Archive host by host for slug hosts', function (): void {
    Sleep::fake();
    fakeWayback(waybackKeys());

    $this->artisan('radar:discover', ['--source' => ['wayback'], '--sync' => true])
        ->expectsOutputToContain('4 nya kandidater')
        ->assertSuccessful();

    expect(Candidate::query()->orderBy('host')->pluck('host')->all())
        ->toBe(['delorean-group.teamtailor.com', 'delorean.teamtailor.com', 'kry.teamtailor.com', 'zenjob.teamtailor.com'])
        ->and(Candidate::query()->findOrFail('delorean.teamtailor.com'))
        ->source->toBe(CandidateSourceType::Wayback)
        ->strength->toBe(Strength::Strong);

    // The nine CDN keys cost one request: the walk jumps past their host.
    // The last request, past zenjob, finds the end.
    Http::assertSentCount(6);
});

it('carries on past a short answer from the Internet Archive', function (): void {
    Sleep::fake();
    $requests = 0;
    fakeWayback(waybackKeys(), function () use (&$requests) {
        // The second answer stops after one key, as the real index sometimes does.
        return ++$requests === 2 ? Http::response("com,teamtailor,cdn,media)/1.png\n") : null;
    });

    $this->artisan('radar:discover', ['--source' => ['wayback'], '--sync' => true])->assertSuccessful();

    expect(Candidate::query()->count())->toBe(4);
});

it('walks the Internet Archive in jobs that carry on where the last one stopped', function (): void {
    Sleep::fake();
    Carbon::setTestNow('2026-11-05 12:00:00');
    fakeWayback(waybackKeys(), function () {
        Carbon::setTestNow(Carbon::now()->addMinutes(25));

        return null;
    });

    $first = new CollectWaybackCandidates();
    app()->call([$first, 'handle']);

    expect($first->chained)->toHaveCount(1);
    $next = unserialize($first->chained[0]);
    expect($next)->toBeInstanceOf(CollectWaybackCandidates::class)
        ->and($next->from)->toBe('com,teamtailor,cdn,media)~');

    app()->call([$next, 'handle']);
    while ($next->chained !== []) {
        $next = unserialize($next->chained[0]);
        app()->call([$next, 'handle']);
    }

    expect(Candidate::query()->count())->toBe(4);
});

it('keeps what was found and tries again later when the Internet Archive goes offline', function (): void {
    Sleep::fake();
    $requests = 0;
    fakeWayback(waybackKeys(), function () use (&$requests) {
        return ++$requests > 3
            ? Http::response('<html><head><title>Internet Archive: Temporarily Offline</title></head></html>')
            : null;
    });

    $job = new CollectWaybackCandidates();
    app()->call([$job, 'handle']);

    $retry = unserialize($job->chained[0]);
    expect(Candidate::query()->orderBy('host')->pluck('host')->all())->toBe(['delorean-group.teamtailor.com', 'delorean.teamtailor.com'])
        ->and($retry->from)->toBe('com,teamtailor,delorean-group)~')
        ->and($retry->failures)->toBe(1)
        ->and($retry->delay)->toBe(CollectWaybackCandidates::RETRY_AFTER);

    // Without progress the failures add up, and the walk gives up.
    $stuck = new CollectWaybackCandidates($retry->from, CollectWaybackCandidates::MAX_FAILURES - 1);
    expect(fn () => app()->call([$stuck, 'handle']))->toThrow(WaybackUnavailable::class);

    $this->artisan('radar:discover', ['--source' => ['wayback'], '--sync' => true])
        ->expectsOutputToContain('svarar inte just nu')
        ->assertFailed();
});

it('syncs a JobStream snapshot, then only changes', function (): void {
    Carbon::setTestNow('2026-09-26 10:00:00');
    Http::fake([
        'jobstream.test/snapshot' => Http::response(json_encode([
            platsbankenAd('1'),
            platsbankenAd('2', ['application_details' => ['url' => 'https://kry.teamtailor.com/jobs/2-x']]),
            platsbankenAd('3', ['application_details' => ['url' => 'https://example.varbi.com/job/3'], 'employer' => ['url' => 'https://www.facebook.com/acme', 'organization_number' => null]]),
            platsbankenAd('4', ['application_details' => ['url' => null], 'employer' => ['url' => 'acme.se', 'organization_number' => '5561234567']]),
        ])),
        'jobstream.test/stream*' => Http::response(json_encode([
            ['id' => '1', 'removed' => true, 'removed_date' => '2026-09-27T09:00:00'],
            platsbankenAd('5', ['application_details' => ['url' => 'https://jobb.acme.se/jobs/5-lager']]),
        ])),
    ]);
    config(['radar.jobstream.base_url' => 'https://jobstream.test']);

    $this->artisan('radar:discover', ['--source' => ['jobstream'], '--sync' => true])
        ->expectsOutputToContain('JobStream: 4 annonser, 0 borttagna, 2 nya domäner')
        ->assertSuccessful();

    expect(PlatsbankenAd::query()->count())->toBe(4)
        ->and(PlatsbankenAd::query()->findOrFail('1'))
        ->apply_host->toBe('career.kry.se')
        ->org_nr->toBe('5569869383')
        ->title_normalized->toBe('sjuksköterska 1')
        ->and(PlatsbankenAd::query()->findOrFail('1')->published_at?->toIso8601String())->toBe('2026-09-20T06:00:00+00:00')
        ->and(Domain::query()->orderBy('base_domain')->pluck('base_domain')->all())->toBe(['acme.se', 'kry.se'])
        ->and(Candidate::query()->orderBy('host')->pluck('host')->all())->toBe(['career.kry.se', 'kry.teamtailor.com']);

    Carbon::setTestNow('2026-09-27 10:00:00');
    $this->artisan('radar:discover', ['--source' => ['jobstream'], '--sync' => true])->assertSuccessful();

    expect(PlatsbankenAd::query()->findOrFail('1')->removed_at)->not->toBeNull()
        ->and(Candidate::query()->pluck('host')->sort()->values()->all())->toBe(['career.kry.se', 'jobb.acme.se', 'kry.teamtailor.com'])
        ->and(DB::table('sync_cursors')->where('name', JobStreamSync::CURSOR)->value('position'))->toStartWith('2026-09-27 10:00:00');

    // Stream asks for changes since the previous run, in Swedish time, one minute early.
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'jobstream.test/stream?date=2026-09-26T11%3A59%3A00'));
});

it('queues the sources as one chain, JobStream sync first', function (): void {
    Bus::fake();

    $this->artisan('radar:discover')->assertSuccessful();

    Bus::assertChained([
        SyncJobStream::class,
        DiscoverPlatformBoards::class,
        fn (CollectCandidates $job): bool => $job->source === CandidateSourceType::JobStream,
        fn (CollectCandidates $job): bool => $job->source === CandidateSourceType::Prefix,
        fn (CollectCandidates $job): bool => $job->source === CandidateSourceType::CommonCrawl,
        fn (CrawlPlatformBoards $job): bool => $job->platform === Platform::Greenhouse,
        fn (CrawlPlatformBoards $job): bool => $job->platform === Platform::Lever,
        fn (CrawlPlatformBoards $job): bool => $job->platform === Platform::Ashby,
        fn (CollectWaybackCandidates $job): bool => $job->from === null,
    ]);
});

it('ends the chain with verification when asked', function (): void {
    Bus::fake();

    $this->artisan('radar:discover', ['--source' => ['prefixes'], '--verify' => true])->assertSuccessful();

    Bus::assertChained([
        fn (CollectCandidates $job): bool => $job->source === CandidateSourceType::Prefix,
        QueueVerification::class,
    ]);
});

it('sends discovery jobs to the long queue connection when configured', function (): void {
    config(['radar.long_queue_connection' => 'redis-long']);

    expect((new SyncJobStream())->connection)->toBe('redis-long')
        ->and((new SyncJobStream())->queue)->toBe('discovery')
        ->and((new CollectCandidates(CandidateSourceType::Prefix))->connection)->toBe('redis-long');

    config(['radar.long_queue_connection' => null]);

    expect((new SyncJobStream())->connection)->toBeNull();
});

it('rejects unknown sources', function (): void {
    Queue::fake();

    $this->artisan('radar:discover', ['--source' => ['altavista']])->assertExitCode(2);

    Queue::assertNothingPushed();
});
