<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Scraping\PlatformAdapters;
use App\Scraping\Teamtailor\TeamtailorAdapter;

it('has an adapter for every scraped platform', function (Platform $platform): void {
    expect(app(PlatformAdapters::class)->for($platform))->not->toBeNull();
})->with(array_filter(Platform::cases(), static fn (Platform $p): bool => $p->isScraped()));

it('has no adapter for Platsbanken, which is synced from JobStream', function (): void {
    app(PlatformAdapters::class)->for(Platform::Platsbanken);
})->throws(InvalidArgumentException::class);

it('reads Teamtailor boards through the Teamtailor adapter', function (): void {
    expect(app(PlatformAdapters::class)->for(Platform::Teamtailor))->toBeInstanceOf(TeamtailorAdapter::class);
});
