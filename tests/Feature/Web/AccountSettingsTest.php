<?php

declare(strict_types=1);

use App\Enums\MarkStatus;
use App\Livewire\AccountSettings;
use App\Models\Board;
use App\Models\HiddenEmployer;
use App\Models\JobPosting;
use App\Models\JobPostingMark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['name' => 'Anna Svensson', 'email' => 'anna@example.com', 'password' => 'gammalt-lösen']);
    $this->actingAs($this->user);
});

it('shows the settings page with what is saved for the account', function (): void {
    $posting = JobPosting::factory()->for(Board::factory()->active())->create();
    JobPostingMark::query()->create(['user_id' => $this->user->id, 'job_posting_id' => $posting->id, 'status' => MarkStatus::Saved]);
    HiddenEmployer::query()->create(['user_id' => $this->user->id, 'employer' => 'Kry']);

    $this->get('/konto')->assertOk()->assertSeeLivewire(AccountSettings::class)
        ->assertSee('Kontoinställningar')
        ->assertSee('anna@example.com')
        ->assertSeeHtml('data-count="marks">1<')
        ->assertSeeHtml('data-count="hidden">1<');
});

it('links the settings from the account menu, and the statistics for admins only', function (): void {
    $this->get('/')->assertOk()->assertSee(route('account'))->assertDontSee(route('stats'))->assertDontSeeHtml('data-admin-badge');

    $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk()->assertSee(route('stats'))->assertSeeHtml('data-admin-badge');
});

it('shows the Gravatar over the initials', function (): void {
    $url = 'https://gravatar.com/avatar/'.hash('sha256', 'anna@example.com').'?s=64&amp;d=404';

    expect(User::factory()->make(['email' => ' Anna@Example.com '])->gravatarUrl(64))
        ->toBe('https://gravatar.com/avatar/'.hash('sha256', 'anna@example.com').'?s=64&d=404');
    $this->get('/')->assertOk()->assertSeeHtml($url)->assertSeeHtml('data-initials>AS<');
});

it('saves the name and email', function (): void {
    Livewire::test(AccountSettings::class)
        ->assertSet('name', 'Anna Svensson')
        ->set('name', ' Anna Berg ')
        ->set('email', 'Anna.Berg@Example.com')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-saved="profile"');

    expect($this->user->fresh())
        ->name->toBe('Anna Berg')
        ->email->toBe('anna.berg@example.com');
});

it('refuses an email that another account uses', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(AccountSettings::class)
        ->set('email', 'taken@example.com')
        ->call('saveProfile')
        ->assertHasErrors('email')
        ->assertSee('Det finns redan ett konto');

    expect($this->user->fresh()->email)->toBe('anna@example.com');
});

it('changes the password only with the current one', function (): void {
    Livewire::test(AccountSettings::class)
        ->set('current_password', 'fel')
        ->set('password', 'nytt-lösenord')
        ->set('password_confirmation', 'nytt-lösenord')
        ->call('updatePassword')
        ->assertHasErrors('current_password')
        ->assertSee('Lösenordet stämmer inte')
        ->set('current_password', 'gammalt-lösen')
        ->call('updatePassword')
        ->assertHasNoErrors()
        ->assertSet('current_password', '')
        ->assertSeeHtml('data-saved="password"');

    expect(Hash::check('nytt-lösenord', $this->user->fresh()->getAuthPassword()))->toBeTrue();
});

it('deletes the account and everything saved for it', function (): void {
    $posting = JobPosting::factory()->for(Board::factory()->active())->create();
    JobPostingMark::query()->create(['user_id' => $this->user->id, 'job_posting_id' => $posting->id, 'status' => MarkStatus::Applied]);
    HiddenEmployer::query()->create(['user_id' => $this->user->id, 'employer' => 'Kry']);

    Livewire::test(AccountSettings::class)
        ->set('delete_password', 'fel')
        ->call('deleteAccount')
        ->assertHasErrors('delete_password');
    expect(User::query()->whereKey($this->user->id)->exists())->toBeTrue();

    Livewire::test(AccountSettings::class)
        ->set('delete_password', 'gammalt-lösen')
        ->call('deleteAccount')
        ->assertRedirect('/');

    expect(User::query()->whereKey($this->user->id)->exists())->toBeFalse()
        ->and(JobPostingMark::query()->count())->toBe(0)
        ->and(HiddenEmployer::query()->count())->toBe(0)
        ->and(Auth::check())->toBeFalse();
});
