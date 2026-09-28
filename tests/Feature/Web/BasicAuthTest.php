<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('is off without credentials, also in production', function (): void {
    $this->get('/')->assertOk();

    app()->detectEnvironment(fn () => 'production');
    $this->get('/')->assertOk();
});

it('requires the configured credentials', function (): void {
    config(['radar.basic_auth.user' => 'marco', 'radar.basic_auth.password' => 'hemligt']);

    $this->get('/')->assertUnauthorized()->assertHeader('WWW-Authenticate');
    $this->get('/stats', ['Authorization' => 'Basic '.base64_encode('marco:fel')])->assertUnauthorized();
    $this->get('/', ['Authorization' => 'Basic '.base64_encode('marco:hemligt')])->assertOk();
});
