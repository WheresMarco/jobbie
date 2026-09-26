<?php

declare(strict_types=1);

use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Enums\Strength;
use App\Jobs\VerifyCandidateBatch;
use App\Models\Board;
use App\Models\Candidate;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\TeamtailorFeed;

uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function verifyBatchHosts(PendingBatch $batch): array
{
    return $batch->jobs->flatMap(fn (VerifyCandidateBatch $job): array => $job->hosts)->all();
}

it('queues new candidates and old rejections, strong first', function (): void {
    Bus::fake();
    Candidate::factory()->create(['host' => 'jobb.weak.se', 'strength' => Strength::Weak, 'discovered_at' => now()->subDays(2)]);
    Candidate::factory()->create(['host' => 'career.strong.se', 'strength' => Strength::Strong, 'discovered_at' => now()->subDay()]);
    Candidate::factory()->create(['host' => 'jobb.old.se', 'stage' => CandidateStage::Rejected, 'checked_at' => now()->subDays(61)]);
    Candidate::factory()->create(['host' => 'jobb.recent.se', 'stage' => CandidateStage::Rejected, 'checked_at' => now()->subDays(5)]);
    Candidate::factory()->create(['host' => 'jobb.match.se', 'stage' => CandidateStage::FingerprintMatch, 'checked_at' => now()->subDays(90)]);
    Candidate::factory()->create(['host' => 'jobb.verified.se', 'stage' => CandidateStage::Verified, 'checked_at' => now()->subDays(90)]);
    Candidate::factory()->create(['host' => 'jobb.nodns.se', 'stage' => CandidateStage::NoDns, 'checked_at' => now()->subDays(90)]);

    $this->artisan('radar:verify', ['--no-wait' => true])->assertSuccessful();

    Bus::assertBatched(function (PendingBatch $batch): bool {
        $hosts = verifyBatchHosts($batch);

        return $batch->name === 'radar:verify'
            && $hosts[0] === 'career.strong.se'
            && collect($hosts)->sort()->values()->all() === ['career.strong.se', 'jobb.match.se', 'jobb.old.se', 'jobb.weak.se'];
    });
});

it('filters by source and limit and splits into batches of 200', function (): void {
    Bus::fake();
    Candidate::factory()->count(450)->create(['source' => CandidateSourceType::Prefix]);
    Candidate::factory()->create(['host' => 'kry.teamtailor.com', 'source' => CandidateSourceType::CommonCrawl]);

    $this->artisan('radar:verify', ['--source' => 'prefixes', '--limit' => 430, '--no-wait' => true])->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 3
        && count(verifyBatchHosts($batch)) === 430
        && ! in_array('kry.teamtailor.com', verifyBatchHosts($batch), true));
});

it('verifies candidates on the sync queue and creates one board per site', function (): void {
    Candidate::factory()->create(['host' => 'career.kry.se', 'strength' => Strength::Weak]);
    Candidate::factory()->create(['host' => 'kry.teamtailor.com', 'strength' => Strength::Strong, 'source' => CandidateSourceType::CommonCrawl]);
    Candidate::factory()->create(['host' => 'careers.einride.tech', 'strength' => Strength::Weak]);
    Http::fake(function (Request $r) {
        return match (parse_url($r->url(), PHP_URL_HOST)) {
            'kry.teamtailor.com' => Http::response('', 301, ['Location' => 'https://career.kry.se/jobs.json']),
            'career.kry.se' => Http::response(TeamtailorFeed::page([TeamtailorFeed::item(1)], 'career.kry.se', 'Kry')),
            default => Http::response('unexpected', 500),
        };
    });

    $this->artisan('radar:verify')
        ->expectsTable(['Steg', 'Kandidater'], [['rejected', '1'], ['verified', '2']])
        ->assertSuccessful();

    expect(Board::query()->count())->toBe(1)
        ->and(Board::query()->firstOrFail())
        ->canonical_host->toBe('career.kry.se')
        ->name->toBe('Kry')
        ->and(Board::query()->firstOrFail()->aliases->pluck('host')->all())->toBe(['kry.teamtailor.com'])
        ->and(Candidate::query()->findOrFail('careers.einride.tech')->reason)->toBe('no_fingerprint')
        ->and(Candidate::query()->findOrFail('career.kry.se')->checked_at)->not->toBeNull();
});

it('says so when there is nothing to verify', function (): void {
    Bus::fake();

    $this->artisan('radar:verify')->expectsOutputToContain('Inga kandidater att verifiera')->assertSuccessful();

    Bus::assertNothingBatched();
});
