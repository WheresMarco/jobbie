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
     * With the user's changes. Added roles and skills come last in the
     * lists shown and first in the search terms, so the term limit never
     * cuts them. Removed ones go, and so do Claude's search terms that
     * contain them: removing PHP also drops "PHP-utvecklare".
     *
     * @param  array{roles: list<string>, skills: list<string>}  $added
     * @param  array{roles: list<string>, skills: list<string>}  $removed
     */
    public function edited(array $added, array $removed): self
    {
        $gone = array_filter(array_map(self::words(...), [...$removed['roles'], ...$removed['skills']]));
        $keep = static fn (array $values, array $removed): array => array_values(array_filter(
            $values,
            static fn (string $v): bool => ! self::contains($removed, $v),
        ));
        $keepTerms = static fn (array $terms): array => array_values(array_filter(
            $terms,
            static function (string $term) use ($gone): bool {
                $words = self::words($term);
                foreach ($gone as $removed) {
                    if (str_contains($words, $removed)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        return new self(
            headline: $this->headline,
            summary: $this->summary,
            seniority: $this->seniority,
            years: $this->years,
            roles: self::merge($keep($this->roles, $removed['roles']), $added['roles']),
            skills: self::merge($keep($this->skills, $removed['skills']), $added['skills']),
            languages: $this->languages,
            locations: $this->locations,
            countries: $this->countries,
            remote: $this->remote,
            titleTerms: self::merge($added['roles'], $keepTerms($this->titleTerms)),
            keywords: self::merge($added['skills'], $keepTerms($this->keywords)),
        );
    }

    /**
     * Whether a list already holds a value, ignoring case.
     *
     * @param  list<string>  $values
     */
    public static function contains(array $values, string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), array_map(mb_strtolower(...), $values), true);
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

    /**
     * Both lists in order, without repeats in any case.
     *
     * @param  list<string>  $first
     * @param  list<string>  $then
     * @return list<string>
     */
    private static function merge(array $first, array $then): array
    {
        $merged = [];
        foreach ([...$first, ...$then] as $value) {
            $merged[mb_strtolower($value)] ??= $value;
        }

        return array_values($merged);
    }

    /**
     * Lower-case words between spaces, for finding one term in another:
     * " php utvecklare " holds " php ". Empty without any words.
     */
    private static function words(string $value): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $words === [] ? '' : ' '.implode(' ', $words).' ';
    }
}
