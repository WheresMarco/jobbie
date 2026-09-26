<?php

declare(strict_types=1);

use App\Enums\BoardKind;
use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Models\Board;
use App\Models\Candidate;
use App\Models\JobPosting;
use App\Scraping\BoardScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TeamtailorFeed;

uses(RefreshDatabase::class);

function redirectToKry(): Closure
{
    return fn (Request $r) => str_starts_with($r->url(), 'https://kry.teamtailor.com/')
        ? Http::response('', 301, ['Location' => 'https://career.kry.se/jobs.json?page=1'])
        : null;
}

it('moves a slug board to the custom domain its feed lives on', function (): void {
    $board = Board::factory()->create(['canonical_host' => 'kry.teamtailor.com', 'tt_slug' => 'kry']);
    TeamtailorFeed::fake('career.kry.se', TeamtailorFeed::items(3), override: redirectToKry());

    app(BoardScraper::class)->scrape($board);

    $board->refresh();
    expect($board->canonical_host)->toBe('career.kry.se')
        ->and($board->kind)->toBe(BoardKind::Custom)
        ->and($board->aliases->pluck('host')->all())->toBe(['kry.teamtailor.com'])
        ->and(Candidate::query()->findOrFail('career.kry.se'))
        ->source->toBe(CandidateSourceType::Redirect)
        ->stage->toBe(CandidateStage::Verified)
        ->source_ref->toBe('kry.teamtailor.com');
});

it('merges a slug board into an existing board for its custom domain', function (): void {
    $custom = Board::factory()->custom('career.kry.se')->create(['first_seen_at' => '2026-01-01']);
    JobPosting::factory()->for($custom)->create(['feed_id' => '1000']);
    $slug = Board::factory()->create(['canonical_host' => 'kry.teamtailor.com', 'first_seen_at' => '2026-03-01']);
    TeamtailorFeed::fake('career.kry.se', TeamtailorFeed::items(3), override: redirectToKry());

    app(BoardScraper::class)->scrape($slug);

    expect(Board::query()->count())->toBe(1)
        ->and(Board::query()->firstOrFail()->id)->toBe($custom->id)
        ->and(JobPosting::query()->where('board_id', $custom->id)->count())->toBe(3)
        ->and(JobPosting::query()->count())->toBe(3);
});

it('merges boards serving the same feed under unrelated hosts after a scrape', function (): void {
    $first = Board::factory()->custom('career.example.se')->create(['first_seen_at' => '2026-01-01']);
    $second = Board::factory()->custom('jobs.example.com')->create(['first_seen_at' => '2026-02-01']);
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(4));
    app(BoardScraper::class)->scrape($first);

    // The second host serves the same four postings but calls itself home.
    TeamtailorFeed::fake('jobs.example.com', TeamtailorFeed::items(4));
    app(BoardScraper::class)->scrape($second);

    expect(Board::query()->count())->toBe(1)
        ->and(Board::query()->firstOrFail()->id)->toBe($first->id)
        ->and(Board::query()->firstOrFail()->aliases->pluck('host')->all())->toBe(['jobs.example.com'])
        ->and(JobPosting::query()->count())->toBe(4);
});
