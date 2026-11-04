<?php

declare(strict_types=1);

use App\Enums\BoardStatus;
use App\Enums\Platform;
use App\Jobs\ScrapeBoard;
use App\Livewire\JobBrowser;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\PlatsbankenAd;
use App\Platforms\Platsbanken;
use App\Stats\RadarStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\PlatformFeeds;

uses(RefreshDatabase::class);

/**
 * A JobStream ad with the fields Platsbanken postings are made from.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function jobStreamAd(string $id, string $applyUrl, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $id,
        'headline' => "Undersköterska {$id}",
        'webpage_url' => "https://arbetsformedlingen.se/platsbanken/annonser/{$id}",
        'publication_date' => '2026-11-02T10:49:25',
        'description' => ['text' => 'Text', 'text_formatted' => '<p><strong>Välkommen</strong> till oss.</p>'],
        'employment_type' => ['label' => 'Vanlig anställning'],
        'salary_description' => 'Månadslön',
        'employer' => ['name' => 'Götene kommun', 'workplace' => 'Äldrecentret', 'organization_number' => '212000-1710'],
        'workplace_address' => ['municipality' => 'Götene', 'city' => null, 'country' => 'Sverige'],
        'application_details' => ['url' => $applyUrl],
        'removed' => false,
    ], $overrides);
}

/**
 * Serve JobStream snapshots and streams in turn; other hosts get 404.
 *
 * @param  list<list<array<string, mixed>>>  $responses
 */
function fakeJobStream(array $responses, ?Closure $other = null): void
{
    $queue = $responses;
    Http::fake(function (Request $request) use (&$queue, $other) {
        if (parse_url($request->url(), PHP_URL_HOST) === 'jobstream.test') {
            return Http::response(json_encode(array_shift($queue)));
        }

        return ($other === null ? null : $other($request)) ?? Http::response('Not found', 404);
    });
}

beforeEach(function (): void {
    config(['radar.jobstream.base_url' => 'https://jobstream.test']);
    Carbon::setTestNow('2026-11-03 06:00:00');
});

it('turns Platsbanken ads into postings on one board and closes removed ads', function (): void {
    fakeJobStream([
        [
            jobStreamAd('100', 'https://gotene.varbi.com/se/what:job/jobID:500/'),
            jobStreamAd('101', 'mailto:jobb@example.se', ['workplace_address' => ['city' => 'Lund', 'country' => 'Danmark']]),
            jobStreamAd('102', 'https://example.se/jobb'),
        ],
        // The stream: one ad removed, one changed.
        [['id' => '101', 'removed' => true, 'removed_date' => '2026-11-03T09:00:00'], jobStreamAd('102', 'https://example.se/jobb', ['headline' => 'Ny rubrik'])],
    ]);

    $this->artisan('radar:discover', ['--source' => ['jobstream'], '--sync' => true])->assertSuccessful();

    $board = Platsbanken::board();
    expect($board->status)->toBe(BoardStatus::Active)
        ->and($board->job_count)->toBe(3)
        ->and($board->siteUrl())->toBeNull();

    $posting = JobPosting::query()->where('board_id', $board->id)->where('feed_id', '100')->firstOrFail();
    expect($posting->title)->toBe('Undersköterska 100')
        ->and($posting->url)->toBe('https://arbetsformedlingen.se/platsbanken/annonser/100')
        ->and($posting->company)->toBe('Götene kommun')
        ->and($posting->cities)->toBe(['Götene'])
        ->and($posting->countries)->toBe(['SE'])
        ->and($posting->employment_type)->toBe('Vanlig anställning')
        ->and($posting->salary_text)->toBe('Månadslön')
        ->and($posting->description)->toBe('Välkommen till oss.')
        // Swedish local time.
        ->and($posting->posted_at?->toIso8601String())->toBe('2026-11-02T09:49:25+00:00')
        ->and($posting->in_platsbanken)->toBeTrue()
        ->and(JobPosting::query()->where('feed_id', '101')->firstOrFail()->countries)->toBe(['DK']);

    Carbon::setTestNow('2026-11-03 10:00:00');
    $this->artisan('radar:discover', ['--source' => ['jobstream'], '--sync' => true])->assertSuccessful();

    expect(JobPosting::query()->where('feed_id', '101')->value('active'))->toBeFalse()
        ->and(JobPosting::query()->where('feed_id', '102')->value('title'))->toBe('Ny rubrik')
        ->and($board->refresh()->job_count)->toBe(2);
});

it('closes postings and ads that a new snapshot no longer has', function (): void {
    fakeJobStream([
        [jobStreamAd('100', 'https://example.se/a'), jobStreamAd('101', 'https://example.se/b')],
        [jobStreamAd('101', 'https://example.se/b')],
    ]);

    $this->artisan('radar:discover', ['--source' => ['jobstream'], '--sync' => true])->assertSuccessful();

    // A cursor older than the stream keeps removals for means a new snapshot.
    Carbon::setTestNow('2027-02-01 06:00:00');
    $this->artisan('radar:discover', ['--source' => ['jobstream'], '--sync' => true])->assertSuccessful();

    expect(JobPosting::query()->where('active', true)->pluck('feed_id')->all())->toBe(['101'])
        ->and(PlatsbankenAd::query()->findOrFail('100')->removed_at)->not->toBeNull()
        ->and(PlatsbankenAd::query()->findOrFail('101')->removed_at)->toBeNull();
});

it('creates boards for the accounts that apply links point at', function (): void {
    config(['radar.platforms.enabled' => ['varbi', 'smartrecruiters', 'workday']]);
    fakeJobStream([[
        jobStreamAd('1', 'https://gotene.varbi.com/se/what:job/jobID:1/type:job/apply:1'),
        jobStreamAd('2', 'https://gotene.varbi.com/se/what:job/jobID:2/'),
        jobStreamAd('3', 'https://jobs.smartrecruiters.com/Vattenfall/744000150707548-inkopare'),
        jobStreamAd('4', 'https://boliden.wd3.myworkdayjobs.com/BolidenJobs/job/Skelleftehamn/Tekniker_JOB_015714'),
        jobStreamAd('5', 'https://recruit.visma.com/spa/public/apply?guidAssignment=abc'),
        // Teamtailor sites go through candidates and verification instead.
        jobStreamAd('6', 'https://kry.teamtailor.com/jobs/4711-lakare'),
    ], []]);

    $this->artisan('radar:discover', ['--source' => ['jobstream'], '--sync' => true])
        ->expectsOutputToContain('varbi: 1 konton i Platsbankens ansökningslänkar, 1 nya karriärsidor.')
        ->assertSuccessful();

    expect(Board::query()->where('platform', '!=', Platform::Platsbanken)->get()
        ->map(fn (Board $b): array => [$b->platform->value, $b->external_key, $b->canonical_host, $b->home_url, $b->status->value])
        ->sortBy(0)->values()->all())
        ->toBe([
            ['smartrecruiters', 'Vattenfall', 'jobs.smartrecruiters.com', 'https://jobs.smartrecruiters.com/Vattenfall', 'new'],
            ['varbi', 'gotene', 'gotene.varbi.com', 'https://gotene.varbi.com/se/', 'new'],
            ['workday', 'boliden.wd3.myworkdayjobs.com/BolidenJobs', 'boliden.wd3.myworkdayjobs.com', 'https://boliden.wd3.myworkdayjobs.com/BolidenJobs', 'new'],
        ]);

    // Running again changes nothing.
    $this->artisan('radar:discover', ['--source' => ['jobstream'], '--sync' => true])
        ->expectsOutputToContain('varbi: 1 konton i Platsbankens ansökningslänkar, 0 nya karriärsidor.')
        ->assertSuccessful();
    expect(Board::query()->count())->toBe(4);
});

it('shows an ad read from both Varbi and Platsbanken once, with places from Platsbanken', function (): void {
    fakeJobStream(
        [[
            jobStreamAd('100', 'https://gotene.varbi.com/se/what:job/jobID:500/type:job/apply:1'),
            jobStreamAd('101', 'https://example.se/jobb'),
        ]],
        fn (Request $r) => parse_url($r->url(), PHP_URL_HOST) === 'gotene.varbi.com'
            ? Http::response(PlatformFeeds::varbiRss('gotene', 'Götene kommun', [500 => 'Undersköterska', 501 => 'Bara på Varbi']))
            : null,
    );

    $this->artisan('radar:discover', ['--source' => ['jobstream'], '--sync' => true])->assertSuccessful();
    $this->artisan('radar:scrape')->assertSuccessful();
    $this->artisan('radar:match-platsbanken')->assertSuccessful();

    $varbi = JobPosting::query()->where('feed_id', '500')->firstOrFail();
    $twin = JobPosting::query()->where('feed_id', '100')->firstOrFail();

    expect($twin->duplicate_of_id)->toBe($varbi->id)
        ->and($varbi->in_platsbanken)->toBeTrue()
        ->and($varbi->platsbanken_ad_id)->toBe('100')
        ->and($varbi->platsbanken_match)->toBe('apply_url')
        ->and($varbi->cities)->toBe(['Götene'])
        ->and($varbi->locations()->pluck('city')->all())->toBe(['Götene']);

    // Three ads, four postings, three in the list.
    Livewire::test(JobBrowser::class)
        ->set('view', 'flat')
        ->assertViewHas('total', 3)
        ->assertViewHas('facets', fn ($f): bool => $f->platforms === ['platsbanken' => 1, 'varbi' => 2])
        // The Varbi posting borrowed its place; the hidden twin counts nowhere.
        ->assertViewHas('facets', fn ($f): bool => $f->cities === ['Götene' => 2])
        ->set('platform', 'platsbanken')
        ->assertViewHas('postings', fn (array $p): bool => array_map(fn ($x) => $x->feed_id, $p) === ['101'])
        ->assertSee('Platsbanken')
        ->assertDontSee('Finns även i Platsbanken');

    expect(app(RadarStats::class)->platforms()['varbi'])->toBe(['boards' => 1, 'active' => 1, 'postings' => 2])
        ->and(app(RadarStats::class)->platforms()['platsbanken']['postings'])->toBe(1)
        ->and(app(RadarStats::class)->activePostings())->toBe(3)
        // The share in Platsbanken counts postings from employers' own systems.
        ->and(app(RadarStats::class)->platsbankenOverlap())->toBe(['active' => 2, 'matched' => 1, 'apply_url' => 1, 'org_title' => 0]);
});

it('stops hiding the Platsbanken posting when the match no longer holds', function (): void {
    $source = Board::factory()->on(Platform::Platsbanken, Platsbanken::KEY)->active()->create();
    $varbi = Board::factory()->on(Platform::Varbi, 'gotene')->active()->create();
    $own = JobPosting::factory()->for($varbi)->create(['feed_id' => '500', 'url' => 'https://gotene.varbi.com/se/what:job/jobID:500/']);
    $twin = JobPosting::factory()->for($source)->create(['feed_id' => '100', 'in_platsbanken' => true]);
    PlatsbankenAd::query()->create(['id' => '100', 'apply_url' => 'https://gotene.varbi.com/se/what:job/jobID:500/']);

    $this->artisan('radar:match-platsbanken')->assertSuccessful();
    expect($twin->refresh()->duplicate_of_id)->toBe($own->id);

    $own->update(['active' => false]);
    $this->artisan('radar:match-platsbanken')->assertSuccessful();

    expect($twin->refresh()->duplicate_of_id)->toBeNull()
        // Its own mark stays: it is a Platsbanken ad.
        ->and($twin->in_platsbanken)->toBeTrue();
});

it('never queues the Platsbanken board for scraping and can scrape one platform', function (): void {
    Bus::fake();
    Board::factory()->on(Platform::Platsbanken, Platsbanken::KEY)->create();
    $varbi = Board::factory()->on(Platform::Varbi, 'gotene')->create();
    Board::factory()->custom('career.kry.se')->create();

    $this->artisan('radar:scrape', ['--no-wait' => true])->assertSuccessful();
    Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === 2);

    $this->artisan('radar:scrape', ['--no-wait' => true, '--platform' => ['varbi']])->assertSuccessful();
    Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof ScrapeBoard
        && $batch->jobs->first()->board->is($varbi));
});

it('keeps the external key of Teamtailor boards in step with the host', function (): void {
    $board = Board::factory()->create(['canonical_host' => 'kry.teamtailor.com']);
    expect($board->external_key)->toBe('kry.teamtailor.com')
        ->and($board->platform)->toBe(Platform::Teamtailor)
        ->and($board->siteUrl())->toBe('https://kry.teamtailor.com/');

    $board->update(['canonical_host' => 'career.kry.se']);
    expect($board->refresh()->external_key)->toBe('career.kry.se');
});
