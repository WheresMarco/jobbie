<?php

declare(strict_types=1);

use App\Livewire\JobBrowser;
use App\Models\Board;
use App\Models\User;
use App\Scraping\JobLocation;
use App\Scraping\JobPostingData;
use App\Scraping\PostingStore;
use App\Tracking\PostingTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  list<JobLocation>  $locations
 */
function postingAt(string $id, array $locations): JobPostingData
{
    return new JobPostingData($id, "Jobb {$id}", "https://career.example.se/jobs/{$id}", 'Exempel AB', $locations, null, null, null, null, null, null);
}

it('lists jobs in Sweden and jobs without a location, not jobs only abroad', function (): void {
    $board = Board::factory()->custom('career.example.se')->active()->create();
    app(PostingStore::class)->upsert($board, [
        'se' => postingAt('se', [new JobLocation('Göteborg', 'SE')]),
        'both' => postingAt('both', [new JobLocation('Oslo', 'NO'), new JobLocation('Malmö', 'SE')]),
        'none' => postingAt('none', []),
        'no' => postingAt('no', [new JobLocation('Oslo', 'NO')]),
        'unknown' => postingAt('unknown', [new JobLocation('Umeå', null)]),
    ], Carbon::now());

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(JobBrowser::class)
        ->set('view', 'flat')
        ->assertViewHas('postings', fn (array $p): bool => collect($p)->pluck('feed_id')->sort()->values()->all() === ['both', 'none', 'se', 'unknown']);

    expect(app(PostingTracker::class)->unseenCount($user))->toBe(4);
});

it('updates the flag when a posting moves abroad', function (): void {
    $board = Board::factory()->custom('career.example.se')->active()->create();
    $store = app(PostingStore::class);

    $store->upsert($board, ['1' => postingAt('1', [new JobLocation('Lund', 'SE')])], Carbon::now());
    expect($board->jobPostings()->value('in_sweden'))->toBeTruthy();

    $store->upsert($board, ['1' => postingAt('1', [new JobLocation('Köpenhamn', 'DK')])], Carbon::now());
    expect($board->jobPostings()->value('in_sweden'))->toBeFalsy();
});
