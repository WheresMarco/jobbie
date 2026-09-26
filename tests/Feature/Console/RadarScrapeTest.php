<?php

declare(strict_types=1);

use App\Enums\BoardStatus;
use App\Jobs\ScrapeBoard;
use App\Models\Board;
use App\Models\BoardAlias;
use App\Models\JobPosting;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Tests\Support\TeamtailorFeed;

uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function batchedHosts(PendingBatch $batch): array
{
    $hosts = $batch->jobs->map(fn (ScrapeBoard $job): string => $job->board->canonical_host)->all();
    sort($hosts);

    return $hosts;
}

it('queues every board except recently checked dead ones', function (): void {
    Bus::fake();
    Board::factory()->custom('a.example.se')->active()->create();
    Board::factory()->custom('b.example.se')->create(['status' => BoardStatus::Empty]);
    Board::factory()->custom('c.example.se')->create(['status' => BoardStatus::Dead, 'checked_at' => now()->subDays(3)]);
    Board::factory()->custom('d.example.se')->create(['status' => BoardStatus::Dead, 'checked_at' => now()->subDays(40)]);
    Board::factory()->custom('e.example.se')->create(['status' => BoardStatus::Error]);

    $this->artisan('radar:scrape', ['--no-wait' => true])->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->name === 'radar:scrape'
        && batchedHosts($batch) === ['a.example.se', 'b.example.se', 'd.example.se', 'e.example.se']);
});

it('limits the run to active boards', function (): void {
    Bus::fake();
    Board::factory()->custom('a.example.se')->active()->create();
    Board::factory()->custom('b.example.se')->create(['status' => BoardStatus::Empty]);

    $this->artisan('radar:scrape', ['--only-active' => true, '--no-wait' => true])->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $batch): bool => batchedHosts($batch) === ['a.example.se']);
});

it('selects boards by canonical host or alias', function (): void {
    Bus::fake();
    $kry = Board::factory()->custom('career.kry.se')->create();
    BoardAlias::factory()->for($kry)->create(['host' => 'kry.teamtailor.com']);
    Board::factory()->custom('jobs.timefold.ai')->create(['status' => BoardStatus::Dead, 'checked_at' => now()]);
    Board::factory()->custom('other.example.se')->create();

    $this->artisan('radar:scrape', ['--host' => ['https://KRY.teamtailor.com/', 'jobs.timefold.ai'], '--no-wait' => true])
        ->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $batch): bool => batchedHosts($batch) === ['career.kry.se', 'jobs.timefold.ai']);
});

it('says so when there is nothing to scrape', function (): void {
    Bus::fake();

    $this->artisan('radar:scrape')->expectsOutputToContain('Inga karriärsidor att skrapa')->assertSuccessful();

    Bus::assertNothingBatched();
});

it('scrapes end to end on the sync queue and prints a summary', function (): void {
    Board::factory()->custom('career.example.se')->create();
    TeamtailorFeed::fake('career.example.se', TeamtailorFeed::items(3));

    $this->artisan('radar:scrape')
        ->expectsOutputToContain('1 karriärsidor köade')
        ->expectsTable(['Status', 'Karriärsidor', 'Annonser'], [['active', '1', '3']])
        ->assertSuccessful();

    expect(JobPosting::query()->count())->toBe(3);
});

it('is unique per board and never overlaps on a host', function (): void {
    $board = Board::factory()->custom('career.example.se')->create();
    $job = new ScrapeBoard($board);

    $middleware = $job->middleware();

    expect($job->uniqueId())->toBe((string) $board->id)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('host:career.example.se')
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
});
