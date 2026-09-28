<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates an account', function (): void {
    $this->artisan('radar:user', ['email' => 'Marco@Example.com'])
        ->expectsQuestion('Lösenord', 'hemligt-lösen')
        ->expectsQuestion('Lösenord igen', 'hemligt-lösen')
        ->expectsOutputToContain('Kontot marco@example.com är skapat')
        ->assertSuccessful();

    $user = User::query()->sole();
    expect($user->email)->toBe('marco@example.com')
        ->and($user->name)->toBe('marco')
        ->and(Hash::check('hemligt-lösen', $user->getAuthPassword()))->toBeTrue();
});

it('sets a new password and name for an existing account', function (): void {
    User::factory()->create(['email' => 'marco@example.com', 'name' => 'Gammalt']);

    $this->artisan('radar:user', ['email' => 'marco@example.com', '--name' => 'Marco'])
        ->expectsQuestion('Lösenord', 'nytt-lösenord')
        ->expectsQuestion('Lösenord igen', 'nytt-lösenord')
        ->assertSuccessful();

    $user = User::query()->sole();
    expect($user->name)->toBe('Marco')
        ->and(Hash::check('nytt-lösenord', $user->getAuthPassword()))->toBeTrue();
});

it('refuses bad input', function (string $email, string $password, string $again): void {
    $command = $this->artisan('radar:user', ['email' => $email]);
    if ($email === 'marco@example.com') {
        $command->expectsQuestion('Lösenord', $password);
        if (mb_strlen($password) >= 8) {
            $command->expectsQuestion('Lösenord igen', $again);
        }
    }
    $command->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'invalid email' => ['inte-en-adress', '', ''],
    'too short' => ['marco@example.com', 'kort', ''],
    'mismatch' => ['marco@example.com', 'hemligt-lösen', 'annat-lösen'],
]);
