<?php

declare(strict_types=1);

use App\Enums\BoardStatus;
use App\Models\Board;
use App\Models\Candidate;
use App\Models\JobPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TeamtailorFeed;

uses(RefreshDatabase::class);

/**
 * Discover, verify and scrape against fake clients, end to end on the
 * sync queue: two Platsbanken ads point at the same Teamtailor site (one
 * through the slug, one through the custom domain) and one at another ATS.
 */
it('finds, verifies and scrapes career sites from Platsbanken', function (): void {
    config(['radar.jobstream.base_url' => 'https://jobstream.test', 'radar.prefixes' => ['jobb']]);

    $snapshot = [
        ['id' => '1', 'headline' => 'Läkare', 'employer' => ['name' => 'Kry', 'url' => 'https://kry.se'], 'application_details' => ['url' => 'https://career.kry.se/jobs/1000-lakare']],
        ['id' => '2', 'headline' => 'Psykolog', 'employer' => ['name' => 'Kry', 'url' => 'https://kry.se'], 'application_details' => ['url' => 'https://kry.teamtailor.com/jobs/1001-psykolog']],
        ['id' => '3', 'headline' => 'Lager', 'employer' => ['name' => 'Acme', 'url' => 'https://acme.se'], 'application_details' => ['url' => 'https://acme.varbi.com/job/3']],
    ];

    Http::fake(function (Request $r) use ($snapshot) {
        $host = parse_url($r->url(), PHP_URL_HOST);
        $path = parse_url($r->url(), PHP_URL_PATH);

        return match (true) {
            $host === 'jobstream.test' => Http::response(json_encode($snapshot)),
            $host === 'kry.teamtailor.com' => Http::response('', 301, ['Location' => 'https://career.kry.se'.$path]),
            $host === 'career.kry.se' && $path === '/jobs.json' => Http::response(TeamtailorFeed::page(TeamtailorFeed::items(120), 'career.kry.se', 'Kry')),
            $host === 'career.kry.se' && $path === '/jobs.rss' => Http::response(TeamtailorFeed::rss(['1000' => ['department' => 'Vård', 'remote' => 'hybrid']])),
            default => Http::response('Not found', 404),
        };
    });

    $this->artisan('radar:discover', ['--source' => ['jobstream', 'prefixes'], '--sync' => true])->assertSuccessful();
    $this->artisan('radar:verify')->assertSuccessful();
    $this->artisan('radar:scrape')->assertSuccessful();

    // One board for the Teamtailor site, reachable from both hosts.
    expect(Board::query()->count())->toBe(1);
    $board = Board::query()->with('aliases')->firstOrFail();
    expect($board->canonical_host)->toBe('career.kry.se')
        ->and($board->status)->toBe(BoardStatus::Active)
        ->and($board->job_count)->toBe(120)
        ->and($board->aliases->pluck('host')->all())->toBe(['kry.teamtailor.com']);

    // Candidates per source: JobStream found both hosts, prefixes guessed two more.
    expect(Candidate::query()->where('source', 'jobstream')->orderBy('host')->pluck('stage', 'host')->map->value->all())->toBe([
        'career.kry.se' => 'verified',
        'kry.teamtailor.com' => 'verified',
    ])->and(Candidate::query()->where('source', 'prefix')->orderBy('host')->pluck('stage', 'host')->map->value->all())->toBe([
        'jobb.acme.se' => 'no_dns',
        'jobb.kry.se' => 'no_dns',
    ]);

    expect(JobPosting::query()->where('active', true)->count())->toBe(120)
        ->and(JobPosting::query()->where('feed_id', '1000')->firstOrFail())
        ->department->toBe('Vård')
        ->remote->value->toBe('hybrid');
});
