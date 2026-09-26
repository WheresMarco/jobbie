<?php

declare(strict_types=1);

use App\Discovery\BoardResolver;
use App\Enums\BoardAliasRelation;
use App\Enums\BoardKind;
use App\Enums\BoardStatus;
use App\Models\Board;
use App\Models\BoardAlias;
use App\Models\JobPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function resolver(): BoardResolver
{
    return app(BoardResolver::class);
}

/**
 * @return array<string, string> alias host => relation
 */
function aliasesOf(Board $board): array
{
    return BoardAlias::query()->where('board_id', $board->id)->orderBy('host')->get()
        ->mapWithKeys(fn (BoardAlias $a): array => [$a->host => $a->relation->value])
        ->all();
}

it('uses the feed home page as canonical host for a slug that redirects', function (): void {
    $board = resolver()->resolve('kry.teamtailor.com', 'career.kry.se', 'https://career.kry.se/', 'Kry');

    expect($board->canonical_host)->toBe('career.kry.se')
        ->and($board->kind)->toBe(BoardKind::Custom)
        ->and($board->tt_slug)->toBe('kry')
        ->and($board->name)->toBe('Kry')
        ->and($board->status)->toBe(BoardStatus::New)
        ->and(aliasesOf($board))->toBe(['kry.teamtailor.com' => 'redirect'])
        ->and(Board::query()->count())->toBe(1);
});

it('falls back to the final host without a home page', function (): void {
    $board = resolver()->resolve('jobb.example.se', 'career.example.se', null);

    expect($board->canonical_host)->toBe('career.example.se')
        ->and(aliasesOf($board))->toBe(['jobb.example.se' => 'redirect']);
});

it('keeps slug boards as tt_subdomain', function (): void {
    $board = resolver()->resolve('acme.teamtailor.com', 'acme.teamtailor.com', 'https://acme.teamtailor.com/');

    expect($board->kind)->toBe(BoardKind::TtSubdomain)
        ->and($board->tt_slug)->toBe('acme')
        ->and(aliasesOf($board))->toBe([]);
});

it('attaches a slug found after its custom domain to the same board', function (): void {
    $first = resolver()->resolve('career.kry.se', 'career.kry.se', 'https://career.kry.se/');
    $second = resolver()->resolve('kry.teamtailor.com', 'career.kry.se', 'https://career.kry.se/');

    expect($second->id)->toBe($first->id)
        ->and(Board::query()->count())->toBe(1)
        ->and(aliasesOf($second))->toBe(['kry.teamtailor.com' => 'redirect']);
});

it('moves a slug board to its custom domain when the feed points there', function (): void {
    $slug = Board::factory()->create(['canonical_host' => 'kry.teamtailor.com', 'tt_slug' => 'kry']);

    $board = resolver()->resolve('kry.teamtailor.com', 'career.kry.se', 'https://career.kry.se/');

    expect($board->id)->toBe($slug->id)
        ->and($board->canonical_host)->toBe('career.kry.se')
        ->and($board->kind)->toBe(BoardKind::Custom)
        ->and($board->tt_slug)->toBe('kry')
        ->and(aliasesOf($board))->toBe(['kry.teamtailor.com' => 'redirect']);
});

it('handles two aliases of the same site in one run', function (): void {
    $a = resolver()->resolve('jobb.kry.se', 'career.kry.se', 'https://career.kry.se/');
    $b = resolver()->resolve('karriar.kry.se', 'career.kry.se', 'https://career.kry.se/');

    expect($b->id)->toBe($a->id)
        ->and(Board::query()->count())->toBe(1)
        ->and(aliasesOf($b))->toBe(['jobb.kry.se' => 'redirect', 'karriar.kry.se' => 'redirect']);
});

it('merges two existing boards that turn out to be the same site', function (): void {
    Carbon::setTestNow('2026-09-01');
    $custom = Board::factory()->custom('career.kry.se')->create(['first_seen_at' => '2026-08-01']);
    $slug = Board::factory()->create(['canonical_host' => 'kry.teamtailor.com', 'first_seen_at' => '2026-07-01']);

    $board = resolver()->resolve('kry.teamtailor.com', 'career.kry.se', 'https://career.kry.se/');

    expect(Board::query()->count())->toBe(1)
        ->and($board->id)->toBe($slug->id)
        ->and($board->canonical_host)->toBe('career.kry.se')
        ->and($board->first_seen_at?->toDateString())->toBe('2026-07-01')
        ->and(Board::query()->find($custom->id))->toBeNull()
        ->and(aliasesOf($board))->toHaveKey('kry.teamtailor.com');
});

it('merges boards with an identical set of at least three postings', function (): void {
    $older = Board::factory()->custom('career.kry.se')->active()->create(['first_seen_at' => '2026-01-01', 'home_url' => 'https://career.kry.se/']);
    $newer = Board::factory()->custom('jobs.kry.com')->active()->create(['first_seen_at' => '2026-05-01']);
    BoardAlias::factory()->for($newer)->create(['host' => 'kry.teamtailor.com']);
    foreach (['1', '2', '3'] as $id) {
        JobPosting::factory()->for($older)->create(['feed_id' => $id]);
        JobPosting::factory()->for($newer)->create(['feed_id' => $id]);
    }
    JobPosting::factory()->for($newer)->create(['feed_id' => '99', 'active' => false]);

    $kept = resolver()->mergeDuplicates($newer);

    expect($kept->id)->toBe($older->id)
        ->and(Board::query()->count())->toBe(1)
        ->and(JobPosting::query()->where('board_id', $older->id)->orderBy('feed_id')->pluck('feed_id')->all())->toBe(['1', '2', '3', '99'])
        ->and(JobPosting::query()->count())->toBe(4)
        ->and($kept->job_count)->toBe(3)
        ->and(aliasesOf($kept))->toBe(['jobs.kry.com' => BoardAliasRelation::Merged->value, 'kry.teamtailor.com' => 'redirect']);
});

it('does not merge boards that share fewer than three postings or differ', function (int $shared, bool $extra): void {
    $a = Board::factory()->custom('a.example.se')->create();
    $b = Board::factory()->custom('b.example.se')->create();
    foreach (range(1, $shared) as $id) {
        JobPosting::factory()->for($a)->create(['feed_id' => (string) $id]);
        JobPosting::factory()->for($b)->create(['feed_id' => (string) $id]);
    }
    if ($extra) {
        JobPosting::factory()->for($b)->create(['feed_id' => '500']);
    }

    resolver()->mergeDuplicates($a);

    expect(Board::query()->count())->toBe(2);
})->with([
    'two shared' => [2, false],
    'superset' => [3, true],
]);

it('takes over the loser host when it is the home page', function (): void {
    $slug = Board::factory()->create(['canonical_host' => 'kry.teamtailor.com', 'home_url' => 'https://career.kry.se/', 'first_seen_at' => '2026-01-01']);
    $custom = Board::factory()->custom('career.kry.se')->create(['first_seen_at' => '2026-02-01']);

    $kept = resolver()->merge($custom, $slug);

    expect($kept->id)->toBe($slug->id)
        ->and($kept->canonical_host)->toBe('career.kry.se')
        ->and(aliasesOf($kept))->toBe(['kry.teamtailor.com' => 'merged']);
});
