<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is open without credentials outside production', function (): void {
    $this->get('/')->assertOk();
});

it('refuses to serve production without credentials', function (): void {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/')->assertForbidden();
});

it('requires the configured credentials', function (): void {
    config(['radar.basic_auth.user' => 'marco', 'radar.basic_auth.password' => 'hemligt']);

    $this->get('/')->assertUnauthorized()->assertHeader('WWW-Authenticate');
    $this->get('/stats', ['Authorization' => 'Basic '.base64_encode('marco:fel')])->assertUnauthorized();
    $this->get('/', ['Authorization' => 'Basic '.base64_encode('marco:hemligt')])->assertOk();
});
