<?php

declare(strict_types=1);

namespace App\Matching;

use App\Models\Profile;

/**
 * A profile compiled for matching: every term becomes a regular expression
 * once, instead of once per posting.
 *
 * Terms of COMPOUND_MIN_LENGTH characters or more also match inside words,
 * since Swedish joins words: "utvecklare" matches "Backendutvecklare".
 * Shorter terms must be whole words, so "java" does not match "javascript"
 * and "go" does not match "google".
 */
final readonly class Criteria
{
    public const int COMPOUND_MIN_LENGTH = 5;

    /** Words in a wanted title that say nothing on their own. */
    private const array STOP_WORDS = ['och', 'eller', 'för', 'med', 'på', 'av', 'i', 'and', 'or', 'of', 'for', 'with', 'the', 'a', 'an', 'in', 'at', '&', '-', '/'];

    /**
     * Wanted titles: the whole phrase, and its meaningful words.
     *
     * @var list<array{title: string, phrase: string, words: list<string>}>
     */
    public array $titlePatterns;

    /** @var array<string, string> term => pattern */
    public array $skillPatterns;

    /** @var array<string, string> term => pattern */
    public array $excludedPatterns;

    /** @var array<string, true> lowercase city => true */
    public array $citySet;

    /** @var array<string, true> JobFilters::REMOTE_OPTIONS value => true */
    public array $remoteSet;

    /**
     * @param  list<string>  $titles
     * @param  list<string>  $skills
     * @param  list<string>  $excluded
     * @param  list<string>  $cities
     * @param  list<string>  $remote
     */
    public function __construct(
        public array $titles = [],
        public array $skills = [],
        public array $excluded = [],
        public array $cities = [],
        public array $remote = [],
    ) {
        $titlePatterns = [];
        foreach ($titles as $title) {
            $words = preg_split('/[\s,\/&()-]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $words = array_values(array_unique(array_diff($words, self::STOP_WORDS)));
            $titlePatterns[] = [
                'title' => $title,
                'phrase' => self::pattern($title),
                'words' => array_map(self::pattern(...), $words),
            ];
        }
        $this->titlePatterns = $titlePatterns;

        $this->skillPatterns = array_combine($skills, array_map(self::pattern(...), $skills));
        $this->excludedPatterns = array_combine($excluded, array_map(self::pattern(...), $excluded));
        $this->citySet = array_fill_keys(array_map(mb_strtolower(...), $cities), true);
        $this->remoteSet = array_fill_keys($remote, true);
    }

    public static function fromProfile(Profile $profile): self
    {
        return new self(
            titles: $profile->titles,
            skills: $profile->skills,
            excluded: $profile->excluded,
            cities: $profile->cities,
            remote: $profile->remote,
        );
    }

    public function hasTitles(): bool
    {
        return $this->titlePatterns !== [];
    }

    public function hasSkills(): bool
    {
        return $this->skillPatterns !== [];
    }

    public function hasLocation(): bool
    {
        return $this->citySet !== [] || $this->remoteSet !== [];
    }

    /**
     * Nothing to score on. Excluded words alone only take postings away.
     */
    public function isEmpty(): bool
    {
        return ! $this->hasTitles() && ! $this->hasSkills() && ! $this->hasLocation();
    }

    /**
     * Case-insensitive pattern for a term. Spaces match any whitespace.
     */
    public static function pattern(string $term): string
    {
        $parts = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $body = implode('\s+', array_map(static fn (string $p): string => preg_quote($p, '/'), $parts));

        if (mb_strlen($term) >= self::COMPOUND_MIN_LENGTH) {
            return "/{$body}/iu";
        }

        return "/(?<![\\p{L}\\p{N}]){$body}(?![\\p{L}\\p{N}])/iu";
    }
}
