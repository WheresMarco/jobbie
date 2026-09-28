<?php

declare(strict_types=1);

use App\Livewire\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['email' => 'marco@example.com', 'password' => 'rätt-lösenord']);
});

it('sends guests to the login page', function (string $path): void {
    $this->get($path)->assertRedirect('/login');
})->with(['/', '/mina', '/stats']);

it('shows the login page to guests only', function (): void {
    $this->get('/login')->assertOk()->assertSeeLivewire(Login::class)->assertSee('Logga in');

    $this->actingAs($this->user)->get('/login')->assertRedirect();
});

it('logs in with the right password and returns to the page asked for', function (): void {
    $this->get('/mina')->assertRedirect('/login');

    Livewire::test(Login::class)
        ->set('email', 'marco@example.com')
        ->set('password', 'rätt-lösenord')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect('/mina');

    $this->assertAuthenticatedAs($this->user);
});

it('refuses a wrong password', function (): void {
    Livewire::test(Login::class)
        ->set('email', 'marco@example.com')
        ->set('password', 'fel')
        ->call('login')
        ->assertHasErrors('email')
        ->assertSee('Fel e-post eller lösenord')
        ->assertSet('password', '');

    $this->assertGuest();
});

it('stops guessing after five attempts', function (): void {
    $component = Livewire::test(Login::class)->set('email', 'marco@example.com');

    foreach (range(1, Login::MAX_ATTEMPTS) as $_) {
        $component->set('password', 'fel')->call('login');
    }

    $component->set('password', 'rätt-lösenord')->call('login')->assertSee('För många försök');
    $this->assertGuest();
});

it('logs out', function (): void {
    $this->actingAs($this->user)
        ->post('/logout')
        ->assertRedirect('/login');

    $this->assertGuest();
});
