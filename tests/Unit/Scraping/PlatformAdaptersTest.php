<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Scraping\PlatformAdapters;
use App\Scraping\Teamtailor\TeamtailorAdapter;

it('has an adapter for every platform', function (Platform $platform): void {
    expect(app(PlatformAdapters::class)->for($platform))->not->toBeNull();
})->with(Platform::cases());

it('reads Teamtailor boards through the Teamtailor adapter', function (): void {
    expect(app(PlatformAdapters::class)->for(Platform::Teamtailor))->toBeInstanceOf(TeamtailorAdapter::class);
});
