<?php

declare(strict_types=1);

namespace App\Matching;

/**
 * What Claude read out of a CV and the user's preferences. Holds no
 * contact details: it is what gets stored instead of the CV itself.
 */
final readonly class Profile
{
    public const array SENIORITIES = ['student', 'junior', 'mid', 'senior', 'lead', 'executive'];

    public const array REMOTE = ['any', 'remote', 'hybrid', 'onsite'];

    /**
     * @param  list<string>  $roles  Job titles the person fits.
     * @param  list<string>  $skills
     * @param  list<string>  $languages  Spoken languages.
     * @param  list<string>  $locations  Cities or regions the person wants to work in.
     * @param  list<string>  $countries  ISO 3166-1 alpha-2 codes, upper case.
     * @param  list<string>  $titleTerms  Words that matching postings have in their titles.
     * @param  list<string>  $keywords  Words that matching postings have in their text.
     */
    public function __construct(
        public string $headline,
        public string $summary,
        public string $seniority,
        public ?int $years,
        public array $roles,
        public array $skills,
        public array $languages,
        public array $locations,
        public array $countries,
        public string $remote,
        public array $titleTerms,
        public array $keywords,
    ) {
    }

    /**
     * Lenient: a stored profile from an older shape still loads.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): string => is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        $list = static function (string $key) use ($data): array {
            $values = is_array($data[$key] ?? null) ? $data[$key] : [];
            $values = array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $values);

            return array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));
        };

        $seniority = $string('seniority');
        $remote = $string('remote');
        $years = $data['years'] ?? null;

        return new self(
            headline: $string('headline'),
            summary: $string('summary'),
            seniority: in_array($seniority, self::SENIORITIES, true) ? $seniority : 'mid',
            years: is_int($years) && $years >= 0 ? $years : null,
            roles: $list('roles'),
            skills: $list('skills'),
            languages: $list('languages'),
            locations: $list('locations'),
            countries: array_values(array_unique(array_filter(
                array_map(strtoupper(...), $list('countries')),
                static fn (string $c): bool => preg_match('/^[A-Z]{2}$/', $c) === 1,
            ))),
            remote: in_array($remote, self::REMOTE, true) ? $remote : 'any',
            titleTerms: $list('title_terms'),
            keywords: $list('keywords'),
        );
    }

    /**
     * The stored shape, which is also the shape Claude returns.
     *
     * @return array{headline: string, summary: string, seniority: string, years: int|null, roles: list<string>, skills: list<string>, languages: list<string>, locations: list<string>, countries: list<string>, remote: string, title_terms: list<string>, keywords: list<string>}
     */
    public function toArray(): array
    {
        return [
            'headline' => $this->headline,
            'summary' => $this->summary,
            'seniority' => $this->seniority,
            'years' => $this->years,
            'roles' => $this->roles,
            'skills' => $this->skills,
            'languages' => $this->languages,
            'locations' => $this->locations,
            'countries' => $this->countries,
            'remote' => $this->remote,
            'title_terms' => $this->titleTerms,
            'keywords' => $this->keywords,
        ];
    }

    /**
     * JSON schema for structured output, matching toArray().
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'is_cv' => ['type' => 'boolean'],
                'headline' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'seniority' => ['type' => 'string', 'enum' => self::SENIORITIES],
                'years' => ['type' => ['integer', 'null']],
                'roles' => $strings,
                'skills' => $strings,
                'languages' => $strings,
                'locations' => $strings,
                'countries' => $strings,
                'remote' => ['type' => 'string', 'enum' => self::REMOTE],
                'title_terms' => $strings,
                'keywords' => $strings,
            ],
            'required' => [
                'is_cv', 'headline', 'summary', 'seniority', 'years', 'roles', 'skills', 'languages',
                'locations', 'countries', 'remote', 'title_terms', 'keywords',
            ],
            'additionalProperties' => false,
        ];
    }
}
