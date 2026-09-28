<?php

declare(strict_types=1);

use App\Enums\RemoteStatus;
use App\Livewire\JobBrowser;
use App\Livewire\ProfilePage;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-28 12:00:00');

    $this->kry = Board::factory()->custom('career.kry.se')->active()->create(['name' => 'Kry']);
    $this->shop = Board::factory()->active()->create(['canonical_host' => 'butik.teamtailor.com', 'name' => 'Butik AB']);

    $make = function (Board $board, string $title, string $description, array $cities, ?RemoteStatus $remote, int $daysAgo): JobPosting {
        $posting = JobPosting::factory()->for($board)->create([
            'title' => $title, 'company' => $board->name, 'description' => $description, 'cities' => $cities,
            'remote' => $remote, 'posted_at' => now()->subDays($daysAgo), 'first_seen_at' => now()->subDays($daysAgo),
        ]);
        foreach ($cities as $city) {
            $posting->locations()->create(['city' => $city, 'country' => 'SE']);
        }

        return $posting;
    };

    // Oldest first, so sorting by date and by match differ.
    $this->backend = $make($this->kry, 'Backendutvecklare', 'PHP, Laravel och MySQL.', ['Göteborg'], RemoteStatus::Hybrid, 10);
    $this->remote = $make($this->shop, 'PHP-utvecklare', 'Laravel på distans.', ['Stockholm'], RemoteStatus::Fully, 8);
    $this->senior = $make($this->kry, 'Senior backendutvecklare', 'PHP och Laravel.', ['Göteborg'], RemoteStatus::Hybrid, 6);
    $this->nurse = $make($this->kry, 'Sjuksköterska', 'Vård i Göteborg.', ['Göteborg'], RemoteStatus::Onsite, 4);
    $this->cashier = $make($this->shop, 'Kassapersonal', 'Butik.', ['Malmö'], RemoteStatus::Onsite, 2);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function saveProfile(): void
{
    Livewire::test(ProfilePage::class)
        ->set('titles', "Backendutvecklare\nPHP developer")
        ->set('skills', 'PHP, Laravel, MySQL, php')
        ->set('excluded', 'senior')
        ->set('cities', 'Göteborg')
        ->set('remote', ['fully', 'hybrid', 'bogus'])
        ->call('save')
        ->assertSet('saved', true);
}

/**
 * @return array<string, int>
 */
function storedScores(User $user): array
{
    return DB::table('job_posting_scores')->join('job_postings', 'job_postings.id', '=', 'job_posting_scores.job_posting_id')
        ->where('user_id', $user->id)->pluck('score', 'title')->map(fn ($s): int => (int) $s)->all();
}

it('saves a cleaned profile and scores every active posting', function (): void {
    saveProfile();

    $profile = $this->user->profile()->firstOrFail();
    expect($profile->titles)->toBe(['backendutvecklare', 'php developer'])
        ->and($profile->skills)->toBe(['php', 'laravel', 'mysql'])
        ->and($profile->excluded)->toBe(['senior'])
        ->and($profile->cities)->toBe(['Göteborg'])
        ->and($profile->remote)->toBe(['fully', 'hybrid'])
        ->and($profile->scored_at)->not->toBeNull()
        ->and($profile->scores_version)->toBe(1);

    // Senior is excluded, and zero scores are not stored. PHP-utvecklare:
    // half of "php developer" (22.5), two of three skills (26.7) and remote (15).
    expect(storedScores($this->user))->toBe([
        'Backendutvecklare' => 100,
        'PHP-utvecklare' => 64,
    ]);

    Livewire::test(ProfilePage::class)
        ->assertSet('skills', "php\nlaravel\nmysql")
        ->assertSee('2 aktiva annonser matchar');
});

it('removes the profile and its scores when every field is emptied', function (): void {
    saveProfile();

    Livewire::test(ProfilePage::class)
        ->set('titles', '')->set('skills', ' , ')->set('excluded', '')->set('cities', '')->set('remote', [])
        ->call('save');

    expect(Profile::query()->count())->toBe(0)
        ->and(DB::table('job_posting_scores')->count())->toBe(0);
});

it('asks for a profile in the job list until there is one', function (): void {
    Livewire::test(JobBrowser::class)
        ->assertSee('Fyll i din profil')
        ->assertDontSee('Bäst matchning')
        ->assertViewHas('byMatch', false);
});

it('sorts the flat list by match, or by date on request', function (): void {
    saveProfile();

    Livewire::test(JobBrowser::class)
        ->set('view', 'flat')
        ->assertViewHas('byMatch', true)
        ->assertViewHas('postings', fn (array $p): bool => array_column($p, 'title') === [
            'Backendutvecklare', 'PHP-utvecklare', 'Kassapersonal', 'Sjuksköterska', 'Senior backendutvecklare',
        ])
        ->assertSee('100 % matchning')
        ->assertSee('Kompetenser: php, laravel, mysql')
        ->set('sort', 'newest')
        ->assertViewHas('byMatch', false)
        ->assertViewHas('postings', fn (array $p): bool => array_column($p, 'title')[0] === 'Kassapersonal');
});

it('orders groups by their best match and postings within them', function (): void {
    saveProfile();

    Livewire::test(JobBrowser::class)
        ->assertViewHas('groups', fn (array $groups): bool => array_map(fn ($g) => $g->employer, $groups) === ['Kry', 'Butik AB']
            && array_column($groups[0]->postings, 'title') === ['Backendutvecklare', 'Sjuksköterska', 'Senior backendutvecklare']);
});

it('filters on matching postings and counts them with the other filters', function (): void {
    saveProfile();

    Livewire::test(JobBrowser::class)
        ->set('view', 'flat')
        ->assertViewHas('facets', fn ($f): bool => $f->matching === 2)
        ->set('matching', true)
        ->assertViewHas('total', 2)
        ->assertViewHas('postings', fn (array $p): bool => array_column($p, 'title') === ['Backendutvecklare', 'PHP-utvecklare'])
        ->set('city', 'Stockholm')
        ->assertViewHas('total', 1)
        ->set('city', 'Malmö')
        ->assertViewHas('total', 0);

    Livewire::withQueryParams(['city' => 'Göteborg'])
        ->test(JobBrowser::class)
        ->assertViewHas('facets', fn ($f): bool => $f->matching === 1);
});

it('scores new postings with radar:match-profiles', function (): void {
    saveProfile();
    JobPosting::factory()->for($this->kry)->create(['title' => 'Laravelutvecklare', 'description' => 'PHP', 'cities' => ['Göteborg']]);

    $this->artisan('radar:match-profiles', ['--sync' => true])->assertSuccessful();

    expect(storedScores($this->user))->toHaveKey('Laravelutvecklare')
        ->and($this->user->profile()->firstOrFail()->scores_version)->toBe(2);
});

it('does not show the matching filter to users of other profiles', function (): void {
    saveProfile();
    $other = User::factory()->create();

    $this->actingAs($other);

    Livewire::test(JobBrowser::class)
        ->set('matching', true)
        ->assertViewHas('total', 5)
        ->assertDontSee('% matchning');
});
