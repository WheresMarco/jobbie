<?php

declare(strict_types=1);

use App\Discovery\JobStream\PlatsbankenAdParser;
use App\Scraping\JobLocation;

it('maps a JobStream ad to a posting', function (): void {
    $data = (new PlatsbankenAdParser())->parse([
        'id' => '30123456',
        'headline' => ' Undersköterska till hemtjänsten ',
        'webpage_url' => 'https://arbetsformedlingen.se/platsbanken/annonser/30123456',
        'publication_date' => '2026-09-20T08:00:00',
        'description' => ['text' => "Vi söker\n\n\n\nen undersköterska."],
        'employer' => ['name' => 'Malmö stad', 'organization_number' => '212000-1124'],
        'workplace_address' => ['municipality' => 'Malmö', 'region' => 'Skåne län', 'country' => 'Sverige'],
        'employment_type' => ['label' => 'Vanlig anställning'],
        'salary_description' => 'Månadslön',
    ]);

    expect($data)->not->toBeNull()
        ->feedId->toBe('30123456')
        ->title->toBe('Undersköterska till hemtjänsten')
        ->company->toBe('Malmö stad')
        ->orgNr->toBe('2120001124')
        ->locations->toEqual([new JobLocation('Malmö', 'SE')])
        ->employmentType->toBe('Vanlig anställning')
        ->salaryText->toBe('Månadslön')
        ->description->toBe("Vi söker\n\nen undersköterska.")
        ->and($data?->postedAt?->toIso8601String())->toBe('2026-09-20T06:00:00+00:00');
});

it('places ads with a municipality but no country in Sweden, and keeps foreign ones abroad', function (): void {
    $parser = new PlatsbankenAdParser();

    expect($parser->parse(['id' => '1', 'headline' => 'A', 'workplace_address' => ['municipality' => 'Kiruna']])?->locations)
        ->toEqual([new JobLocation('Kiruna', 'SE')])
        ->and($parser->parse(['id' => '2', 'headline' => 'B', 'workplace_address' => ['city' => 'Oslo', 'country' => 'Norge']])?->locations)
        ->toEqual([new JobLocation('Oslo', 'NO')])
        ->and($parser->parse(['id' => '3', 'headline' => 'C'])?->locations)->toBe([]);
});

it('falls back to the Platsbanken ad page and skips ads without a headline', function (): void {
    $parser = new PlatsbankenAdParser();

    expect($parser->parse(['id' => '7', 'headline' => 'A'])?->url)->toBe(PlatsbankenAdParser::AD_URL.'7')
        ->and($parser->parse(['id' => '8', 'headline' => ' ']))->toBeNull()
        ->and($parser->parse(['headline' => 'No id']))->toBeNull();
});
