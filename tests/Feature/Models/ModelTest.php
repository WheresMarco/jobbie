<?php

declare(strict_types=1);

use App\Enums\BoardAliasRelation;
use App\Enums\BoardKind;
use App\Enums\BoardStatus;
use App\Enums\CandidateStage;
use App\Enums\RemoteStatus;
use App\Enums\Strength;
use App\Models\Board;
use App\Models\BoardAlias;
use App\Models\Candidate;
use App\Models\Domain;
use App\Models\JobPosting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates boards with enum casts and relations', function (): void {
    $board = Board::factory()->custom('career.kry.se')->active()->create();
    BoardAlias::factory()->for($board)->create(['host' => 'kry.teamtailor.com']);
    JobPosting::factory()->count(2)->for($board)->create();

    $board->refresh();

    expect($board->kind)->toBe(BoardKind::Custom)
        ->and($board->status)->toBe(BoardStatus::Active)
        ->and($board->feedUrl())->toBe('https://career.kry.se/jobs.json')
        ->and($board->aliases->first()?->relation)->toBe(BoardAliasRelation::Redirect)
        ->and($board->jobPostings)->toHaveCount(2);
});

it('defaults new boards to status new', function (): void {
    $board = Board::query()->create(['canonical_host' => 'jobs.example.se', 'kind' => BoardKind::Custom]);

    expect($board->refresh()->status)->toBe(BoardStatus::New)
        ->and($board->job_count)->toBe(0);
});

it('keeps canonical hosts unique', function (): void {
    Board::factory()->custom('career.kry.se')->create();
    Board::factory()->custom('career.kry.se')->create();
})->throws(QueryException::class);

it('keeps one posting per board and feed id', function (): void {
    $board = Board::factory()->create();
    JobPosting::factory()->for($board)->create(['feed_id' => '42']);
    JobPosting::factory()->for($board)->create(['feed_id' => '42']);
})->throws(QueryException::class);

it('casts job posting attributes', function (): void {
    $posting = JobPosting::factory()->create([
        'cities' => ['Stockholm', 'Göteborg'],
        'countries' => ['SE'],
        'remote' => RemoteStatus::Hybrid,
    ]);
    $posting->locations()->createMany([
        ['city' => 'Stockholm', 'country' => 'SE'],
        ['city' => 'Göteborg', 'country' => 'SE'],
    ]);

    $posting = JobPosting::query()->with('locations', 'board')->findOrFail($posting->id);

    expect($posting->cities)->toBe(['Stockholm', 'Göteborg'])
        ->and($posting->remote)->toBe(RemoteStatus::Hybrid)
        ->and($posting->active)->toBeTrue()
        ->and($posting->locations->pluck('city')->all())->toBe(['Stockholm', 'Göteborg'])
        ->and($posting->board)->toBeInstanceOf(Board::class);
});

it('uses hosts and base domains as string keys', function (): void {
    $candidate = Candidate::factory()->create(['host' => 'jobb.example.se']);
    $domain = Domain::factory()->create(['base_domain' => 'example.se']);

    expect(Candidate::query()->find('jobb.example.se')?->stage)->toBe(CandidateStage::New)
        ->and($candidate->strength)->toBe(Strength::Weak)
        ->and(Domain::query()->find('example.se')?->company_name)->toBe($domain->company_name);
});
