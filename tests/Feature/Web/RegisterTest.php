<?php

declare(strict_types=1);

use App\Livewire\Register;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  array<string, string>  $overrides
 */
function registration(array $overrides = []): \Livewire\Features\SupportTesting\Testable
{
    $fields = [
        'name' => 'Marco',
        'email' => 'Marco@Example.com',
        'password' => 'hemligt-lösen',
        'password_confirmation' => 'hemligt-lösen',
        ...$overrides,
    ];

    $component = Livewire::test(Register::class);
    foreach ($fields as $field => $value) {
        $component->set($field, $value);
    }

    return $component->call('register');
}

it('shows the registration page and links it from the login page', function (): void {
    $this->get('/register')->assertOk()->assertSeeLivewire(Register::class)->assertSee('Skapa konto');
    $this->get('/login')->assertSee('/register', false);
});

it('creates an account and logs in', function (): void {
    registration()->assertHasNoErrors()->assertRedirect('/');

    $user = User::query()->sole();
    expect($user->email)->toBe('marco@example.com')
        ->and($user->name)->toBe('Marco')
        ->and(Hash::check('hemligt-lösen', $user->getAuthPassword()))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('refuses invalid input', function (array $overrides, string $field, string $message): void {
    registration($overrides)->assertHasErrors($field)->assertSee($message);

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
})->with([
    'no name' => [['name' => ' '], 'name', 'Ange ditt namn'],
    'invalid email' => [['email' => 'inte-en-adress'], 'email', 'Ange en giltig e-postadress'],
    'short password' => [['password' => 'kort', 'password_confirmation' => 'kort'], 'password', 'minst 8 tecken'],
    'mismatch' => [['password_confirmation' => 'annat-lösen'], 'password', 'Lösenorden stämmer inte'],
]);

it('refuses an address that already has an account, in any case', function (): void {
    User::factory()->create(['email' => 'marco@example.com']);

    registration(['email' => 'MARCO@example.com'])->assertHasErrors('email')->assertSee('Det finns redan ett konto');
    expect(User::query()->count())->toBe(1);
});

it('limits registrations per address and hour', function (): void {
    foreach (range(1, Register::MAX_PER_HOUR) as $i) {
        auth()->logout();
        registration(['email' => "user{$i}@example.com"])->assertHasNoErrors();
    }
    auth()->logout();

    registration(['email' => 'one-more@example.com'])->assertHasErrors('email')->assertSee('För många försök');
    expect(User::query()->count())->toBe(Register::MAX_PER_HOUR);
});

it('can be turned off', function (): void {
    config(['radar.registration' => false]);

    $this->get('/register')->assertNotFound();
    $this->get('/login')->assertDontSee('/register', false);
});

it('is not shown to logged in users', function (): void {
    $this->actingAs(User::factory()->create())->get('/register')->assertRedirect();
});
