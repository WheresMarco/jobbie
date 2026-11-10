<?php

declare(strict_types=1);

use App\Livewire\Welcome;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('greets the user by first name and counts postings and employers', function (): void {
    $kry = Board::factory()->custom('career.kry.se')->active()->create(['name' => 'Kry']);
    $slug = Board::factory()->active()->create(['canonical_host' => 'bemanning.teamtailor.com', 'name' => 'Bemanning AB']);
    JobPosting::factory()->for($kry)->count(2)->create(['company' => null]);
    // A recruiter's board lists postings for another employer.
    JobPosting::factory()->for($slug)->create(['company' => 'Kund AB']);
    JobPosting::factory()->for($slug)->create(['active' => false]);

    $this->actingAs(User::factory()->create(['name' => 'Anna Svensson']))
        ->get('/valkommen')
        ->assertOk()
        ->assertSeeLivewire(Welcome::class)
        ->assertSee('Hej Anna, kul att du är här!')
        ->assertSeeInOrder(['Aktiva annonser', '3', 'Arbetsgivare', '2'])
        ->assertSee(route('jobs'))
        ->assertSee(route('mine'));
});

it('leaves the numbers out while the database is empty', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/valkommen')->assertOk()->assertDontSee('data-numbers', false);
});

it('needs a login', function (): void {
    $this->get('/valkommen')->assertRedirect('/login');
});

it('links the welcome page from the account menu', function (): void {
    $this->actingAs(User::factory()->create(['name' => 'Anna Svensson']))
        ->get('/')->assertOk()->assertSee(route('welcome'))->assertSeeHtml('data-initials>AS<');
});

it('makes initials from the first and last name', function (string $name, string $initials): void {
    expect(User::factory()->make(['name' => $name])->initials())->toBe($initials);
})->with([
    ['Anna Svensson', 'AS'],
    ['Anna Maria Svensson', 'AS'],
    ['marco', 'M'],
    ['  Örjan  Åberg ', 'ÖÅ'],
]);

it('shows branded error pages', function (): void {
    $this->get('/finns-inte')->assertNotFound()->assertSee('Sidan finns inte')->assertSee('/icon.svg', false);
});
