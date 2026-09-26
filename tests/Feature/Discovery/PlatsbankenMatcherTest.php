<?php

declare(strict_types=1);

use App\Livewire\JobBrowser;
use App\Livewire\StatsPage;
use App\Models\Board;
use App\Models\BoardAlias;
use App\Models\Domain;
use App\Models\JobPosting;
use App\Models\PlatsbankenAd;
use App\Stats\RadarStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->kry = Board::factory()->custom('career.kry.se')->active()->create();
    BoardAlias::factory()->for($this->kry)->create(['host' => 'kry.teamtailor.com']);
    $this->acme = Board::factory()->custom('jobb.acme.se')->active()->create();

    $post = fn (Board $board, string $feedId, string $url, string $title) => JobPosting::factory()->for($board)
        ->create(['feed_id' => $feedId, 'url' => $url, 'title' => $title]);

    // Same Teamtailor job id, linked through the slug host in Platsbanken.
    $post($this->kry, '1', 'https://career.kry.se/jobs/4711-lakare', 'Läkare');
    // Same organisation number (learned from the match above) and title.
    $post($this->kry, '2', 'https://career.kry.se/jobs/4712-psykolog', 'Psykolog  ');
    // Only on the career site.
    $post($this->kry, '3', 'https://career.kry.se/jobs/4713-kurator', 'Kurator');
    // Organisation number from the domains table.
    $post($this->acme, '4', 'https://jobb.acme.se/jobs/99-lager', 'Lagerarbetare');
    // Identical URL without a Teamtailor job id.
    $post($this->acme, '5', 'https://jobb.acme.se/apply/abc', 'Truckförare');

    Domain::factory()->create(['base_domain' => 'acme.se', 'org_nr' => '5561234567']);

    $ad = fn (string $id, array $attributes) => PlatsbankenAd::query()->create(['id' => $id, ...$attributes]);
    $ad('a1', ['apply_url' => 'https://kry.teamtailor.com/jobs/4711-lakare?utm=af', 'org_nr' => '5569869383', 'title_normalized' => 'läkare']);
    $ad('a2', ['apply_url' => 'https://www.kry.se/jobba', 'org_nr' => '5569869383', 'title_normalized' => 'psykolog']);
    $ad('a3', ['apply_url' => null, 'org_nr' => '5561234567', 'title_normalized' => 'lagerarbetare']);
    $ad('a4', ['apply_url' => 'jobb.acme.se/apply/abc/', 'org_nr' => null, 'title_normalized' => 'truckförare']);
    $ad('a5', ['apply_url' => 'https://career.kry.se/jobs/4713-kurator', 'org_nr' => '5569869383', 'title_normalized' => 'kurator', 'removed_at' => now()]);
});

it('matches postings by apply link, then by organisation number and title', function (): void {
    $this->artisan('radar:match-platsbanken')
        ->expectsOutputToContain('4 av 5 aktiva annonser (80,0 %) finns även i Platsbanken: 2 via ansökningslänk, 2 via organisationsnummer och titel.')
        ->assertSuccessful();

    $matches = JobPosting::query()->orderBy('feed_id')->get(['feed_id', 'in_platsbanken', 'platsbanken_ad_id', 'platsbanken_match'])
        ->mapWithKeys(fn ($p) => [$p->feed_id => [$p->in_platsbanken, $p->platsbanken_ad_id, $p->platsbanken_match]])->all();

    expect($matches)->toBe([
        '1' => [true, 'a1', 'apply_url'],
        '2' => [true, 'a2', 'org_title'],
        '3' => [false, null, null],
        '4' => [true, 'a3', 'org_title'],
        '5' => [true, 'a4', 'apply_url'],
    ]);
});

it('clears matches that no longer hold', function (): void {
    $this->artisan('radar:match-platsbanken')->assertSuccessful();
    PlatsbankenAd::query()->update(['removed_at' => now()]);

    $this->artisan('radar:match-platsbanken')->assertSuccessful();

    expect(JobPosting::query()->where('in_platsbanken', true)->count())->toBe(0);
});

it('marks and filters postings in the job browser and shows the share', function (): void {
    $this->artisan('radar:match-platsbanken')->assertSuccessful();

    Livewire::test(JobBrowser::class)
        ->set('view', 'flat')
        ->assertSee('Finns även i Platsbanken')
        ->assertViewHas('facets', fn ($f): bool => $f->notInPlatsbanken === 1)
        ->set('missing', true)
        ->assertViewHas('postings', fn (array $p): bool => array_map(fn ($x) => $x->feed_id, $p) === ['3']);

    expect(app(RadarStats::class)->platsbankenOverlap())->toBe(['active' => 5, 'matched' => 4, 'apply_url' => 2, 'org_title' => 2]);
    Livewire::test(StatsPage::class)->assertSeeHtml('80,0 %');
});
