<?php

declare(strict_types=1);

use App\Enums\MarkStatus;
use App\Livewire\JobBrowser;
use App\Livewire\MyPostings;
use App\Livewire\UnseenCounter;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\JobPostingMark;
use App\Models\User;
use App\Tracking\PostingTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-28 12:00:00');

    $board = Board::factory()->custom('career.kry.se')->active()->create(['name' => 'Kry']);
    $make = fn (string $title, string $firstSeen, array $attributes = []): JobPosting => JobPosting::factory()->for($board)->create([
        'title' => $title, 'company' => 'Kry', 'first_seen_at' => $firstSeen, ...$attributes,
    ]);

    $this->backend = $make('Backendutvecklare', '2026-09-20 08:00:00');
    $this->nurse = $make('Sjuksköterska', '2026-09-25 08:00:00');
    $this->psych = $make('Psykolog', '2026-09-27 08:00:00');
    $this->closed = $make('Stängd tjänst', '2026-09-01 08:00:00', ['active' => false]);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * @return list<string>
 */
function listedTitles(\Livewire\Features\SupportTesting\Testable $component): array
{
    return collect($component->viewData('postings'))->pluck('title')->sort()->values()->all();
}

it('marks a posting and clears the mark by clicking the same status again', function (): void {
    $component = Livewire::test(JobBrowser::class)->set('view', 'flat');

    $component->call('mark', $this->backend->id, 'saved')
        ->assertSeeHtml('data-status="saved"');

    $mark = JobPostingMark::query()->sole();
    expect($mark->status)->toBe(MarkStatus::Saved)
        ->and($mark->user_id)->toBe($this->user->id);

    $component->call('mark', $this->backend->id, 'applied');
    expect($mark->fresh()->status)->toBe(MarkStatus::Applied);

    $component->call('mark', $this->backend->id, '')
        ->assertDontSeeHtml('data-status=');
    // Cleared, but still seen.
    expect($mark->fresh()->status)->toBeNull();
});

it('hides rejected postings from the job list', function (): void {
    $component = Livewire::test(JobBrowser::class)->set('view', 'flat')
        ->call('mark', $this->nurse->id, 'rejected');

    expect(listedTitles($component))->toBe(['Backendutvecklare', 'Psykolog'])
        ->and($component->viewData('total'))->toBe(2);
});

it('lists unseen postings until they are marked, opened or everything is marked as seen', function (): void {
    $component = Livewire::test(JobBrowser::class)->set('view', 'flat')->set('unseen', true);

    expect(listedTitles($component))->toBe(['Backendutvecklare', 'Psykolog', 'Sjuksköterska'])
        ->and($component->viewData('facets')->unseen)->toBe(3);

    // Marking and opening both count as seen.
    $component->call('mark', $this->backend->id, 'saved')->call('markSeen', $this->nurse->id);
    expect(listedTitles($component))->toBe(['Psykolog']);

    $component->call('markAllSeen');
    expect(listedTitles($component))->toBe([])
        ->and($component->viewData('facets')->unseen)->toBe(0);

    // Postings discovered later are unseen again.
    Carbon::setTestNow('2026-09-29 03:00:00');
    JobPosting::factory()->for($this->backend->board)->create(['title' => 'Ny tjänst', 'first_seen_at' => now()]);
    expect(listedTitles($component->call('$refresh')))->toBe(['Ny tjänst'])
        ->and(app(PostingTracker::class)->unseenCount($this->user->fresh()))->toBe(1);
});

it('counts unseen postings in the navigation', function (): void {
    $this->get('/')->assertOk()->assertSeeInOrder(['Osedda', '3']);

    app(PostingTracker::class)->markAllSeen($this->user);

    $this->get('/')->assertOk()->assertDontSee('data-unseen-count', false);
});

it('refreshes the unseen count in the navigation when marks change', function (): void {
    Livewire::test(JobBrowser::class)
        ->call('markSeen', $this->nurse->id)->assertDispatched(UnseenCounter::MARKS_CHANGED)
        ->call('mark', $this->psych->id, 'saved')->assertDispatched(UnseenCounter::MARKS_CHANGED)
        ->call('markAllSeen')->assertDispatched(UnseenCounter::MARKS_CHANGED);

    $counter = Livewire::test(UnseenCounter::class)->assertDontSeeHtml('data-unseen-count');

    Carbon::setTestNow('2026-09-29 03:00:00');
    JobPosting::factory()->for($this->backend->board)->create(['first_seen_at' => now()]);

    $counter->dispatch(UnseenCounter::MARKS_CHANGED)->assertSeeHtml('data-unseen-count>1<');
});

it('keeps each user\'s marks apart', function (): void {
    Livewire::test(JobBrowser::class)->call('mark', $this->nurse->id, 'rejected');

    $this->actingAs(User::factory()->create());

    $component = Livewire::test(JobBrowser::class)->set('view', 'flat')->set('unseen', true);
    expect(listedTitles($component))->toBe(['Backendutvecklare', 'Psykolog', 'Sjuksköterska']);
});

it('ignores marks for postings that do not exist', function (): void {
    Livewire::test(JobBrowser::class)->call('mark', 999_999, 'saved')->call('markSeen', 999_999);

    expect(JobPostingMark::query()->count())->toBe(0);
});

it('lists marked postings per status on the my postings page, closed ones included', function (): void {
    $tracker = app(PostingTracker::class);
    $tracker->mark($this->user, $this->backend->id, MarkStatus::Saved);
    Carbon::setTestNow('2026-09-28 12:05:00');
    $tracker->mark($this->user, $this->closed->id, MarkStatus::Saved);
    $tracker->mark($this->user, $this->nurse->id, MarkStatus::Applied);
    $tracker->mark($this->user, $this->psych->id, MarkStatus::Rejected);

    $component = Livewire::test(MyPostings::class)
        // Latest change first.
        ->assertViewHas('postings', fn (array $p): bool => array_column($p, 'title') === ['Stängd tjänst', 'Backendutvecklare'])
        ->assertViewHas('counts', ['saved' => 2, 'applied' => 1, 'rejected' => 1])
        ->assertSee('Stängd');

    $component->set('status', 'rejected')
        ->assertViewHas('postings', fn (array $p): bool => array_column($p, 'title') === ['Psykolog']);

    // Moving a posting out of the tab removes it from the list.
    $component->call('mark', $this->psych->id, '')
        ->assertViewHas('postings', [])
        ->assertSee('Inga nekade annonser');

    $this->get('/mina?status=applied')->assertOk()->assertSee('Sjuksköterska')->assertDontSee('Backendutvecklare');
});
