<?php

declare(strict_types=1);

use App\Enums\BoardStatus;
use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Livewire\StatsPage;
use App\Models\Board;
use App\Models\BoardAlias;
use App\Models\Candidate;
use App\Models\Domain;
use App\Models\User;
use App\Stats\RadarStats;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $kry = Board::factory()->custom('career.kry.se')->active()->create(['job_count' => 12]);
    BoardAlias::factory()->for($kry)->create(['host' => 'kry.teamtailor.com']);
    Board::factory()->active()->create(['canonical_host' => 'acme.teamtailor.com', 'job_count' => 3]);
    Board::factory()->create(['canonical_host' => 'gone.teamtailor.com', 'status' => BoardStatus::Dead]);

    $candidate = fn (string $host, CandidateSourceType $source, CandidateStage $stage, ?string $ref = null) => Candidate::factory()
        ->create(['host' => $host, 'source' => $source, 'stage' => $stage, 'source_ref' => $ref]);

    $candidate('career.kry.se', CandidateSourceType::JobStream, CandidateStage::Verified);
    $candidate('kry.teamtailor.com', CandidateSourceType::CommonCrawl, CandidateStage::Verified);
    $candidate('acme.teamtailor.com', CandidateSourceType::CommonCrawl, CandidateStage::Verified);
    $candidate('gone.teamtailor.com', CandidateSourceType::CommonCrawl, CandidateStage::Verified);
    $candidate('beta.teamtailor.com', CandidateSourceType::CommonCrawl, CandidateStage::New);
    $candidate('jobb.kry.se', CandidateSourceType::Prefix, CandidateStage::NoDns, 'jobb');
    $candidate('career.acme.se', CandidateSourceType::Prefix, CandidateStage::Verified, 'career');

    Domain::factory()->create(['base_domain' => 'kry.se', 'txt_match' => true]);
    Domain::factory()->create(['base_domain' => 'hidden.se', 'txt_match' => true]);
    Domain::factory()->create(['base_domain' => 'other.se', 'txt_match' => false]);
});

it('counts candidates, active boards and postings per source', function (): void {
    $stats = collect(app(RadarStats::class)->sources())->keyBy(fn ($s) => $s->source->value);

    expect($stats['commoncrawl']->stages)->toBe(['new' => 1, 'verified' => 3])
        ->and($stats['commoncrawl']->tested())->toBe(3)
        ->and($stats['commoncrawl']->verified())->toBe(3)
        // kry via alias and acme; the dead board does not count.
        ->and($stats['commoncrawl']->activeBoards)->toBe(2)
        ->and($stats['commoncrawl']->postings)->toBe(15)
        ->and($stats['jobstream']->activeBoards)->toBe(1)
        ->and($stats['prefix']->activeBoards)->toBe(0);
});

it('shows kinds, statuses, prefix hits and TXT customers without a board', function (): void {
    $stats = app(RadarStats::class);

    expect($stats->boardsByKind())->toBe(['tt_subdomain' => 2, 'custom' => 1])
        ->and($stats->boardsByStatus())->toBe(['new' => 0, 'active' => 2, 'empty' => 0, 'dead' => 1, 'error' => 0])
        ->and($stats->prefixHits())->toBe(['career' => 1])
        ->and($stats->txtMatchesWithoutBoard())->toBe(['hidden.se']);
});

it('prints the statistics', function (): void {
    $this->artisan('radar:stats')
        ->expectsOutputToContain('Common Crawl (D)')
        ->expectsOutputToContain('hidden.se')
        ->assertSuccessful();
});

it('renders the statistics page', function (): void {
    Livewire::test(StatsPage::class)
        ->assertSee('Common Crawl (D)')
        ->assertSee('hidden.se')
        ->assertSee('Egen domän');

    $this->actingAs(User::factory()->admin()->create())->get('/stats')->assertOk();
});

it('schedules scraping, discovery, the suffix list and Horizon snapshots', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => [$e->command, $e->expression])->all();
    $find = fn (string $needle) => collect($commands)->first(fn ($c) => str_contains((string) $c[0], $needle));

    expect($find('radar:scrape')[1])->toBe('0 3 * * *')
        ->and($find('radar:discover --verify')[1])->toBe('0 1 * * 1')
        ->and($find('radar:update-psl')[1])->toBe('0 4 * * 0')
        ->and($find('radar:match-platsbanken')[1])->toBe('30 5 * * *')
        ->and($find('horizon:snapshot')[1])->toBe('*/5 * * * *');
});
