<?php

declare(strict_types=1);

use App\Enums\MarkStatus;
use App\Livewire\JobBrowser;
use App\Livewire\MyPostings;
use App\Livewire\UnseenCounter;
use App\Models\Board;
use App\Models\HiddenEmployer;
use App\Models\JobPosting;
use App\Models\User;
use App\Tracking\PostingTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-28 12:00:00');

    $this->kry = Board::factory()->custom('career.kry.se')->active()->create(['name' => 'Kry']);
    $this->staffing = Board::factory()->active()->create(['canonical_host' => 'bemanning.teamtailor.com', 'name' => 'Bemanning AB']);

    $this->backend = JobPosting::factory()->for($this->kry)->create(['title' => 'Backendutvecklare', 'company' => 'Kry', 'first_seen_at' => now()->subDay()]);
    $this->nurse = JobPosting::factory()->for($this->kry)->create(['title' => 'Sjuksköterska', 'company' => 'Kry', 'first_seen_at' => now()->subDay()]);
    // Postings the staffing company publishes for a client, and under its own name.
    $this->client = JobPosting::factory()->for($this->staffing)->create(['title' => 'Lagerarbetare', 'company' => 'Kund AB', 'first_seen_at' => now()->subDay()]);
    $this->own = JobPosting::factory()->for($this->staffing)->create(['title' => 'Truckförare', 'company' => null, 'first_seen_at' => now()->subDay()]);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * @return list<string>
 */
function visibleTitles(): array
{
    return collect(Livewire::test(JobBrowser::class)->set('view', 'flat')->viewData('postings'))
        ->pluck('title')->sort()->values()->all();
}

it('hides a posting marked as not relevant and lists it under my postings', function (): void {
    Livewire::test(JobBrowser::class)->call('mark', $this->nurse->id, 'not_relevant');

    expect(visibleTitles())->toBe(['Backendutvecklare', 'Lagerarbetare', 'Truckförare']);

    Livewire::test(MyPostings::class)
        ->set('status', 'not_relevant')
        ->assertViewHas('postings', fn (array $p): bool => array_column($p, 'title') === ['Sjuksköterska'])
        ->assertViewHas('counts', fn (array $c): bool => $c['not_relevant'] === 1)
        // Clearing the mark brings it back.
        ->call('mark', $this->nurse->id, '');

    expect(visibleTitles())->toContain('Sjuksköterska');
});

it('hides every posting from a company, including later ones', function (): void {
    Livewire::test(JobBrowser::class)->call('hideEmployer', 'Kry')->assertDispatched(UnseenCounter::MARKS_CHANGED);

    expect(visibleTitles())->toBe(['Lagerarbetare', 'Truckförare']);

    JobPosting::factory()->for($this->kry)->create(['title' => 'Psykolog', 'company' => 'Kry']);
    expect(visibleTitles())->toBe(['Lagerarbetare', 'Truckförare']);
});

it('hides by the name the list groups by, so a client of a staffing company is separate', function (): void {
    // The staffing company's own postings have no company and group under the board name.
    Livewire::test(JobBrowser::class)->call('hideEmployer', 'Bemanning AB');
    expect(visibleTitles())->toBe(['Backendutvecklare', 'Lagerarbetare', 'Sjuksköterska']);

    Livewire::test(JobBrowser::class)->call('hideEmployer', 'Kund AB');
    expect(visibleTitles())->toBe(['Backendutvecklare', 'Sjuksköterska']);
});

it('leaves hidden companies out of groups, facets and the unseen count', function (): void {
    $tracker = app(PostingTracker::class);
    expect($tracker->unseenCount($this->user->fresh()))->toBe(4);

    $component = Livewire::test(JobBrowser::class)->call('hideEmployer', 'Kry');

    expect(collect($component->viewData('groups'))->pluck('employer')->all())->not->toContain('Kry')
        ->and($component->viewData('total'))->toBe(2)
        ->and($component->viewData('facets')->unseen)->toBe(2)
        ->and($tracker->unseenCount($this->user->fresh()))->toBe(2);

    // A seen posting from a hidden company is not subtracted twice.
    $tracker->markSeen($this->user->fresh(), [$this->backend->id]);
    expect($tracker->unseenCount($this->user->fresh()))->toBe(2);
});

it('lists hidden companies on the not relevant tab and shows them again', function (): void {
    $tracker = app(PostingTracker::class);
    $tracker->hideEmployer($this->user, 'Kry');
    $tracker->hideEmployer($this->user, 'Kund AB');

    Livewire::test(MyPostings::class)
        ->assertViewHas('hiddenEmployers', [])
        ->set('status', 'not_relevant')
        ->assertViewHas('hiddenEmployers', ['Kry', 'Kund AB'])
        ->assertSee('Dolda företag (2)')
        ->call('showEmployer', 'Kry')
        ->assertViewHas('hiddenEmployers', ['Kund AB']);

    expect(visibleTitles())->toBe(['Backendutvecklare', 'Sjuksköterska', 'Truckförare']);
});

it('keeps hidden companies per user', function (): void {
    app(PostingTracker::class)->hideEmployer($this->user, 'Kry');

    $this->actingAs(User::factory()->create());
    expect(visibleTitles())->toBe(['Backendutvecklare', 'Lagerarbetare', 'Sjuksköterska', 'Truckförare']);
});

it('ignores empty and duplicate company names', function (): void {
    $tracker = app(PostingTracker::class);
    $tracker->hideEmployer($this->user, '  ');
    $tracker->hideEmployer($this->user, 'Kry');
    $tracker->hideEmployer($this->user, 'Kry');

    expect(HiddenEmployer::query()->pluck('employer')->all())->toBe(['Kry']);
});

it('offers the buttons in both views', function (): void {
    Livewire::test(JobBrowser::class)
        ->assertSeeHtml('data-hide-employer')
        ->assertSee('Ej relevant')
        ->set('view', 'flat')
        ->assertSee('Dölj företaget');
});

it('treats rejected and not relevant as the statuses that hide', function (): void {
    expect(MarkStatus::hiding())->toBe([MarkStatus::Rejected, MarkStatus::NotRelevant])
        ->and(MarkStatus::Saved->hides())->toBeFalse();
});
