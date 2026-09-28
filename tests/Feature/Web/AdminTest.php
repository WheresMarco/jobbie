<?php

declare(strict_types=1);

use App\Livewire\Register;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the statistics to admins only', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/stats')->assertForbidden();
    $this->get('/')->assertOk()->assertDontSee(route('stats'));

    $this->actingAs(User::factory()->admin()->create())
        ->get('/stats')->assertOk();
    $this->get('/')->assertOk()->assertSee(route('stats'));
});

it('opens Horizon to admins only outside local', function (): void {
    app()->detectEnvironment(fn () => 'production');

    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create())->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('viewHorizon'))->toBeTrue();
});

it('never makes a registered user an admin', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Någon')
        ->set('email', 'nagon@example.com')
        ->set('password', 'hemligt-lösen')
        ->set('password_confirmation', 'hemligt-lösen')
        ->call('register')
        ->assertHasNoErrors();

    expect(User::query()->sole()->is_admin)->toBeFalse();
    $this->get('/stats')->assertForbidden();
});

it('grants and revokes admin rights', function (): void {
    $user = User::factory()->create(['email' => 'marco@example.com']);

    $this->artisan('radar:admin', ['email' => 'Marco@Example.com'])
        ->expectsOutputToContain('marco@example.com är nu admin')
        ->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeTrue();

    $this->artisan('radar:admin', ['email' => 'marco@example.com', '--revoke' => true])
        ->expectsOutputToContain('inte längre admin')
        ->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeFalse();

    $this->artisan('radar:admin', ['email' => 'saknas@example.com'])->assertFailed();
});

it('creates an admin account from the command line', function (): void {
    $this->artisan('radar:user', ['email' => 'admin@example.com', '--admin' => true])
        ->expectsQuestion('Lösenord', 'hemligt-lösen')
        ->expectsQuestion('Lösenord igen', 'hemligt-lösen')
        ->assertSuccessful();

    expect(User::query()->sole()->is_admin)->toBeTrue();
});

it('keeps new accounts from the command line without admin rights by default', function (): void {
    $this->artisan('radar:user', ['email' => 'marco@example.com'])
        ->expectsQuestion('Lösenord', 'hemligt-lösen')
        ->expectsQuestion('Lösenord igen', 'hemligt-lösen')
        ->assertSuccessful();

    expect(User::query()->sole()->is_admin)->toBeFalse();
});
