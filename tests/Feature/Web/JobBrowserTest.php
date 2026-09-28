<?php

declare(strict_types=1);

use App\Enums\RemoteStatus;
use App\Livewire\JobBrowser;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  list<array{string, string}>  $locations  city, country
 * @param  array<string, mixed>  $attributes
 */
function posting(Board $board, array $attributes = [], array $locations = [['Stockholm', 'SE']]): JobPosting
{
    $posting = JobPosting::factory()->for($board)->create([
        'cities' => array_values(array_unique(array_column($locations, 0))),
        'countries' => array_values(array_unique(array_column($locations, 1))),
        ...$attributes,
    ]);
    foreach ($locations as [$city, $country]) {
        $posting->locations()->create(['city' => $city, 'country' => $country]);
    }

    return $posting;
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');

    $this->kry = Board::factory()->custom('career.kry.se')->active()->create(['name' => 'Kry']);
    $this->slug = Board::factory()->active()->create(['canonical_host' => 'bemanning.teamtailor.com', 'name' => 'Bemanning AB']);

    posting($this->kry, ['feed_id' => '1', 'title' => 'Backendutvecklare', 'company' => 'Kry', 'remote' => RemoteStatus::Fully,
        'department' => 'Teknik', 'posted_at' => now()->subHours(5), 'first_seen_at' => now()->subDays(2),
        'description' => 'Vi bygger vård med PHP och Laravel i ett litet team.']);
    posting(
        $this->kry,
        ['feed_id' => '2', 'title' => 'Sjuksköterska', 'company' => 'Kry', 'remote' => RemoteStatus::Hybrid,
        'department' => 'Vård', 'posted_at' => now()->subDays(5), 'first_seen_at' => now()->subDays(20)],
        [['Göteborg', 'SE'], ['Oslo', 'NO']]
    );
    posting($this->kry, ['feed_id' => '3', 'title' => 'Psykolog', 'company' => 'Kry', 'remote' => RemoteStatus::None,
        'department' => 'Vård', 'posted_at' => now()->subDays(20), 'first_seen_at' => now()->subDays(20)], [['Oslo', 'NO']]);
    posting($this->slug, ['feed_id' => '4', 'title' => 'Lagerarbetare', 'company' => 'Kund AB', 'remote' => null,
        'department' => null, 'posted_at' => now()->subDays(40), 'first_seen_at' => now()->subDays(1)], [['Malmö', 'SE']]);
    posting($this->slug, ['feed_id' => '5', 'title' => 'Truckförare', 'company' => null, 'remote' => RemoteStatus::Onsite,
        'posted_at' => now()->subDays(2), 'first_seen_at' => now()->subDays(30)], [['Malmö', 'SE']]);
    posting($this->slug, ['feed_id' => '6', 'title' => 'Gammal annons', 'active' => false]);
});

it('shows every active posting grouped by employer', function (): void {
    Livewire::test(JobBrowser::class)
        ->assertViewHas('total', 5)
        ->assertViewHas('groups', fn (array $groups): bool => array_map(fn ($g) => [$g->employer, $g->total], $groups) === [
            ['Kry', 3], ['Bemanning AB', 1], ['Kund AB', 1],
        ])
        ->assertSee('Backendutvecklare')
        ->assertDontSee('Gammal annons')
        ->assertSee('https://career.kry.se/');
});

it('filters on each field', function (array $params, array $expected): void {
    Livewire::withQueryParams($params)
        ->test(JobBrowser::class)
        ->set('view', 'flat')
        ->assertViewHas('postings', fn (array $postings): bool => collect($postings)->pluck('feed_id')->sort()->values()->all() === $expected);
})->with([
    'free text' => [['q' => 'sjuk'], ['2']],
    'free text in description' => [['q' => 'laravel team'], ['1']],
    'free text in company' => [['q' => 'kund'], ['4']],
    'country' => [['country' => 'NO'], ['2', '3']],
    'city' => [['city' => 'Malmö'], ['4', '5']],
    'remote' => [['remote' => ['fully']], ['1']],
    'on site includes none' => [['remote' => ['onsite']], ['3', '5']],
    'not stated' => [['remote' => ['unknown']], ['4']],
    'several remote options' => [['remote' => ['fully', 'hybrid']], ['1', '2']],
    'department' => [['department' => 'Vård'], ['2', '3']],
    'published within a day' => [['published' => '1'], ['1']],
    'published within a week' => [['published' => '7'], ['1', '2', '5']],
    'new' => [['new' => true], ['1', '4']],
    'custom domain' => [['kind' => 'custom'], ['1', '2', '3']],
    'Teamtailor address' => [['kind' => 'tt_subdomain'], ['4', '5']],
    'combined' => [['country' => 'SE', 'department' => 'Vård'], ['2']],
    'invalid values are ignored' => [['published' => '3', 'kind' => 'x', 'remote' => ['sometimes'], 'country' => 'Sverige'], ['1', '2', '3', '4', '5']],
]);

it('counts each facet with the other filters applied', function (): void {
    $facets = Livewire::withQueryParams(['country' => 'SE', 'remote' => ['hybrid', 'fully']])
        ->test(JobBrowser::class)
        ->viewData('facets');

    // Country ignores its own filter but keeps the remote filter.
    expect($facets->countries)->toBe(['SE' => 2, 'NO' => 1])
        // Remote ignores its own filter but keeps the country filter.
        ->and($facets->remote)->toBe(['fully' => 1, 'hybrid' => 1, 'onsite' => 1, 'unknown' => 1])
        ->and($facets->cities)->toBe(['Göteborg' => 1, 'Oslo' => 1, 'Stockholm' => 1])
        ->and($facets->departments)->toBe(['Teknik' => 1, 'Vård' => 1])
        ->and($facets->published)->toBe([1 => 1, 7 => 2, 30 => 2])
        ->and($facets->new)->toBe(1)
        ->and($facets->kinds)->toBe(['custom' => 2]);
});

it('keeps filter state in the URL', function (): void {
    Livewire::withQueryParams(['q' => 'utvecklare', 'remote' => ['fully'], 'view' => 'flat', 'new' => '1'])
        ->test(JobBrowser::class)
        ->assertSet('q', 'utvecklare')
        ->assertSet('remote', ['fully'])
        ->assertSet('view', 'flat')
        ->assertSet('new', true)
        ->set('country', 'SE')
        ->assertSet('country', 'SE');

    $this->actingAs(User::factory()->create())->get('/?q=psyk&country=NO&view=flat')
        ->assertOk()
        ->assertSeeText('Psykolog')
        ->assertDontSee('Backendutvecklare');
});

it('shows more on request and starts over when a filter changes', function (): void {
    foreach (range(10, 40) as $i) {
        posting($this->kry, ['feed_id' => (string) $i, 'title' => "Extra {$i}", 'company' => "Bolag {$i}"]);
    }

    Livewire::test(JobBrowser::class)
        ->set('view', 'flat')
        ->assertViewHas('postings', fn (array $p): bool => count($p) === JobBrowser::PAGE_SIZE)
        ->assertViewHas('hasMore', true)
        ->call('showMore')
        ->assertSet('limit', JobBrowser::PAGE_SIZE * 2)
        ->assertViewHas('postings', fn (array $p): bool => count($p) === 36)
        ->assertViewHas('hasMore', false)
        ->set('q', 'extra')
        ->assertSet('limit', JobBrowser::PAGE_SIZE);
});

it('marks search terms in titles and description snippets', function (): void {
    Livewire::withQueryParams(['q' => 'laravel backend'])
        ->test(JobBrowser::class)
        ->assertSeeHtml('<mark>Backend</mark>utvecklare')
        ->assertSeeHtml('PHP och <mark>Laravel</mark>');
});

it('clears all filters', function (): void {
    Livewire::withQueryParams(['q' => 'nothing matches this', 'country' => 'NO'])
        ->test(JobBrowser::class)
        ->assertViewHas('total', 0)
        ->assertSee('Inga annonser matchar filtren')
        ->call('clearFilters')
        ->assertSet('q', '')
        ->assertSet('country', '')
        ->assertViewHas('total', 5);
});

it('explains an empty database', function (): void {
    JobPosting::query()->delete();

    Livewire::test(JobBrowser::class)->assertSee('Inga annonser ännu');
});

it('groups postings without company under the career site name', function (): void {
    $groups = Livewire::withQueryParams(['q' => 'truck'])->test(JobBrowser::class)->viewData('groups');

    expect($groups[0]->employer)->toBe('Bemanning AB')
        ->and($groups[0]->siteUrl)->toBe('https://bemanning.teamtailor.com/')
        ->and($groups[0]->cities)->toBe(['Malmö']);
});

it('renders the page', function (): void {
    $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSeeLivewire(JobBrowser::class)->assertSee('Jobbie');
});

it('reuses cached search results without crashing on a second request', function (): void {
    cache()->flush();
    $this->actingAs(User::factory()->create());

    $this->get('/')->assertOk();
    $this->get('/')->assertOk();
});

it('opens only the first group until the viewer toggles groups', function (): void {
    $component = Livewire::test(JobBrowser::class);
    $groups = $component->viewData('groups');

    expect($groups[0]->open)->toBeTrue()
        ->and($groups[0]->postings)->toHaveCount(3)
        ->and($groups[1]->open)->toBeFalse()
        ->and($groups[1]->postings)->toBe([])
        // Cities come from the scan, so closed groups still show them.
        ->and($groups[1]->cities)->toBe(['Malmö']);

    $component->call('toggleGroup', 'Bemanning AB', ['Kry']);
    $groups = $component->viewData('groups');

    expect($component->get('open'))->toBe(['Kry', 'Bemanning AB'])
        ->and($groups[1]->postings)->toHaveCount(1)
        ->and($groups[1]->siteUrl)->toBe('https://bemanning.teamtailor.com/');

    $component->call('toggleGroup', 'Kry', ['Kry', 'Bemanning AB']);

    expect($component->get('open'))->toBe(['Bemanning AB'])
        ->and($component->viewData('groups')[0]->postings)->toBe([]);

    // A new filter starts over with the first group open.
    $component->set('q', 'kry');
    expect($component->get('open'))->toBeNull();
});
