<?php

declare(strict_types=1);

use App\Enums\RemoteStatus;
use App\Scraping\CountryCode;
use App\Scraping\JobFeedParser;
use App\Scraping\JobLocation;
use App\Scraping\RssEnrichment;
use Tests\Support\TeamtailorFeed;

function fullItem(): array
{
    return json_decode(test()->fixture('feeds/item-full.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('maps every field of a full item', function (): void {
    $data = (new JobFeedParser())->parse(fullItem(), null, 'Feed title');

    expect($data)->not->toBeNull()
        ->and($data->feedId)->toBe('4711001')
        ->and($data->title)->toBe('Backendutvecklare & tech lead')
        ->and($data->url)->toBe('https://career.example.se/jobs/4711001-backendutvecklare')
        ->and($data->company)->toBe('Exempel Bemanning AB')
        ->and($data->locations)->toEqual([
            new JobLocation('Stockholm', 'SE'),
            new JobLocation('Göteborg', 'SE'),
            new JobLocation('Oslo', 'NO'),
        ])
        ->and($data->cities())->toBe(['Stockholm', 'Göteborg', 'Oslo'])
        ->and($data->countries())->toBe(['SE', 'NO'])
        ->and($data->remote)->toBe(RemoteStatus::Fully)
        ->and($data->department)->toBeNull()
        ->and($data->employmentType)->toBe('FULL_TIME, CONTRACTOR')
        ->and($data->salaryText)->toBe('45 000–60 000 SEK/MONTH')
        ->and($data->postedAt?->toIso8601String())->toBe('2026-09-10T07:30:00+00:00');
});

it('decodes the description twice, strips tags and drops scripts', function (): void {
    $description = (new JobFeedParser())->parse(fullItem())?->description;

    expect($description)->toBe("Vi söker en backendutvecklare.\nPHP\nLaravel\n\nLön & förmåner enligt avtal.");
});

it('prefers RSS remote status and department', function (): void {
    $data = (new JobFeedParser())->parse(fullItem(), new RssEnrichment('Teknik', RemoteStatus::Hybrid));

    expect($data?->remote)->toBe(RemoteStatus::Hybrid)
        ->and($data?->department)->toBe('Teknik');
});

it('leaves remote empty without RSS data or TELECOMMUTE', function (): void {
    expect((new JobFeedParser())->parse(TeamtailorFeed::item(1))?->remote)->toBeNull();
});

it('falls back to the feed title and date_published', function (): void {
    $item = TeamtailorFeed::item(1);
    unset($item['_jobposting']['hiringOrganization'], $item['_jobposting']['datePosted']);

    $data = (new JobFeedParser())->parse($item, null, 'Feedbolaget');

    expect($data?->company)->toBe('Feedbolaget')
        ->and($data?->postedAt?->toIso8601String())->toBe('2026-09-01T06:00:00+00:00');
});

it('accepts a single jobLocation object and a plain address string', function (): void {
    $single = TeamtailorFeed::item(1);
    $single['_jobposting']['jobLocation'] = ['@type' => 'Place', 'address' => ['addressLocality' => 'Malmö', 'addressCountry' => 'SE']];
    $text = TeamtailorFeed::item(2);
    $text['_jobposting']['jobLocation'] = [['@type' => 'Place', 'address' => 'Umeå']];

    expect((new JobFeedParser())->parse($single)?->locations)->toEqual([new JobLocation('Malmö', 'SE')])
        ->and((new JobFeedParser())->parse($text)?->locations)->toEqual([new JobLocation('Umeå', null)]);
});

it('formats single and hourly salaries', function (): void {
    $item = TeamtailorFeed::item(1, ['_jobposting' => ['baseSalary' => [
        'currency' => 'SEK', 'value' => ['value' => '185.5', 'unitText' => 'hour'],
    ]]]);

    expect((new JobFeedParser())->parse($item)?->salaryText)->toBe('185.50 SEK/HOUR');
});

it('skips items without id, title or url', function (string $field): void {
    $item = TeamtailorFeed::item(1);
    unset($item[$field]);
    if ($field !== 'id') {
        unset($item['_jobposting'][$field]);
    }

    expect((new JobFeedParser())->parse($item))->toBeNull();
})->with(['id', 'title', 'url']);

it('caps descriptions at 8000 characters', function (): void {
    $description = JobFeedParser::description('<p>'.str_repeat('å', 9000).'</p>');

    expect(mb_strlen((string) $description))->toBe(JobFeedParser::DESCRIPTION_LIMIT)
        ->and($description)->toEndWith('…')
        ->and(JobFeedParser::description('<p> </p>'))->toBeNull();
});

it('normalises country names and codes to ISO 3166 alpha-2', function (?string $input, ?string $expected): void {
    expect(CountryCode::normalize($input))->toBe($expected);
})->with([
    ['Sweden', 'SE'],
    ['Sverige', 'SE'],
    ['se', 'SE'],
    [' Norway ', 'NO'],
    ['Norge', 'NO'],
    ['Finland', 'FI'],
    ['Danmark', 'DK'],
    ['United Kingdom', 'GB'],
    ['UK', 'GB'],
    ['USA', 'US'],
    ['Tyskland', 'DE'],
    ['European Union', null],
    ['Atlantis', null],
    ['', null],
    [null, null],
]);
