<?php

declare(strict_types=1);

use App\Matching\Criteria;
use App\Matching\Matcher;
use App\Matching\MatchResult;

/**
 * @param  list<string>  $cities
 */
function matchPosting(Criteria $criteria, string $title, ?string $description = null, array $cities = [], ?string $remote = null, ?string $department = null): MatchResult
{
    return (new Matcher())->match($criteria, $title, $department, $description, $cities, $remote);
}

it('gives full title points for the whole phrase and a share for some of its words', function (): void {
    $criteria = new Criteria(titles: ['backend developer', 'php-utvecklare']);

    expect(matchPosting($criteria, 'Senior Backend Developer')->score)->toBe(100)
        ->and(matchPosting($criteria, 'Backend Engineer')->score)->toBe(50)
        ->and(matchPosting($criteria, 'Backend Engineer')->title)->toBe('backend developer')
        ->and(matchPosting($criteria, 'Sjuksköterska')->score)->toBe(0)
        ->and(matchPosting($criteria, 'Sjuksköterska')->title)->toBeNull();
});

it('finds long terms inside compound words but short terms only as whole words', function (): void {
    expect(matchPosting(new Criteria(titles: ['utvecklare']), 'Backendutvecklare')->score)->toBe(100)
        ->and(matchPosting(new Criteria(skills: ['java']), 'Utvecklare', 'Vi skriver JavaScript.')->skills)->toBe([])
        ->and(matchPosting(new Criteria(skills: ['java']), 'Utvecklare', 'Vi skriver Java och Kotlin.')->skills)->toBe(['java'])
        ->and(matchPosting(new Criteria(skills: ['c#']), 'Utvecklare', 'Erfarenhet av C# och .NET')->skills)->toBe(['c#'])
        ->and(matchPosting(new Criteria(skills: ['go']), 'Utvecklare', 'Vi använder Google Cloud.')->skills)->toBe([]);
});

it('gives full skill points from four skills, whatever the number listed', function (): void {
    $criteria = new Criteria(skills: ['php', 'laravel', 'mysql', 'redis', 'docker', 'aws']);

    expect(matchPosting($criteria, 'Utvecklare', 'PHP och Laravel')->score)->toBe(50)
        ->and(matchPosting($criteria, 'Utvecklare', 'PHP, Laravel, MySQL och Redis')->score)->toBe(100)
        ->and(matchPosting(new Criteria(skills: ['php', 'laravel']), 'Utvecklare', 'PHP och Laravel')->score)->toBe(100);
});

it('searches skills in title, department and description', function (): void {
    $criteria = new Criteria(skills: ['php', 'teknik', 'laravel']);

    expect(matchPosting($criteria, 'PHP-utvecklare', 'Vi använder Laravel.', department: 'Teknik')->skills)
        ->toBe(['php', 'teknik', 'laravel']);
});

it('scores zero when the title has an excluded word', function (): void {
    $criteria = new Criteria(titles: ['utvecklare'], excluded: ['senior']);

    $result = matchPosting($criteria, 'Senior utvecklare');

    expect($result->score)->toBe(0)->and($result->excluded)->toBe('senior')
        ->and(matchPosting($criteria, 'Utvecklare', 'Du arbetar med seniora kollegor.')->score)->toBe(100);
});

it('fits the location on city or remote work, within the accepted ways of working', function (?string $remote, array $cities, bool $fits): void {
    $criteria = new Criteria(cities: ['Göteborg'], remote: ['fully', 'hybrid']);

    expect(matchPosting($criteria, 'Utvecklare', cities: $cities, remote: $remote)->location)->toBe($fits);
})->with([
    'wanted city, hybrid' => ['hybrid', ['Göteborg'], true],
    'city in another case' => ['hybrid', ['göteborg'], true],
    'other city, fully remote' => ['fully', ['Stockholm'], true],
    'other city, hybrid' => ['hybrid', ['Stockholm'], false],
    'wanted city, on site' => ['onsite', ['Göteborg'], false],
    'wanted city, no remote work' => ['none', ['Göteborg'], false],
    'wanted city, way of working unknown' => [null, ['Göteborg'], true],
]);

it('scales the parts the profile fills in to 100', function (): void {
    $criteria = new Criteria(titles: ['utvecklare'], skills: ['php'], cities: ['Malmö']);

    // Title 45 + location 15 of 100.
    expect(matchPosting($criteria, 'Utvecklare', 'Vi använder Ruby.', ['Malmö'])->score)->toBe(60)
        // Skills 40 of 55 without titles.
        ->and(matchPosting(new Criteria(skills: ['php'], cities: ['Malmö']), 'Utvecklare', 'PHP', ['Stockholm'])->score)->toBe(73)
        ->and(matchPosting(new Criteria(excluded: ['senior']), 'Utvecklare')->score)->toBe(0)
        ->and((new Criteria(excluded: ['senior']))->isEmpty())->toBeTrue();
});
