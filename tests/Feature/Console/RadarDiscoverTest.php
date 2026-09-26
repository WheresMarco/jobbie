<?php

declare(strict_types=1);

use App\Discovery\JobStream\JobStreamSync;
use App\Enums\CandidateSourceType;
use App\Enums\Strength;
use App\Jobs\CollectCandidates;
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

it('queues sources, chaining the JobStream sync before collecting', function (): void {
    Bus::fake();

    $this->artisan('radar:discover')->assertSuccessful();

    Bus::assertChained([SyncJobStream::class, fn (CollectCandidates $job): bool => $job->source === CandidateSourceType::JobStream]);
    Bus::assertDispatched(CollectCandidates::class, fn (CollectCandidates $job): bool => $job->source === CandidateSourceType::Prefix);
    Bus::assertDispatched(CollectCandidates::class, fn (CollectCandidates $job): bool => $job->source === CandidateSourceType::CommonCrawl);
});

it('rejects unknown sources', function (): void {
    Queue::fake();

    $this->artisan('radar:discover', ['--source' => ['altavista']])->assertExitCode(2);

    Queue::assertNothingPushed();
});
