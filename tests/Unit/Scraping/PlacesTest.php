<?php

declare(strict_types=1);

use App\Scraping\JobLocation;
use App\Scraping\Places;

it('reads a place from free text', function (string $text, ?string $city, ?string $country): void {
    expect(Places::parse($text))->toEqual($city === null && $country === null ? null : new JobLocation($city, $country));
})->with([
    'city and country' => ['Stockholm, Sweden', 'Stockholm', 'SE'],
    'known city alone' => ['Göteborg', 'Göteborg', 'SE'],
    'without diacritics' => ['Malmo', 'Malmö', 'SE'],
    'English name' => ['Gothenburg, Sweden', 'Göteborg', 'SE'],
    'with a word after' => ['Stockholm HQ', 'Stockholm', 'SE'],
    'remote in a country' => ['Remote - Sweden', null, 'SE'],
    'in brackets' => ['Lund (Hybrid)', 'Lund', 'SE'],
    'another country wins' => ['Lund, Norway', 'Lund', 'NO'],
    'unknown city' => ['Gurugram, Haryana, India', 'Gurugram', 'IN'],
    'unknown city alone' => ['Gurgaon', 'Gurgaon', null],
    'common names are not places' => ['Mora', 'Mora', null],
    'nothing' => ['Remote', null, null],
]);

it('reads several places', function (): void {
    expect(Places::parseAll('London; Stockholm or Remote - Sweden'))->toEqual([
        new JobLocation('London', null),
        new JobLocation('Stockholm', 'SE'),
        new JobLocation(null, 'SE'),
    ])->and(Places::parseAll(null))->toBe([]);
});
