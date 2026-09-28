<?php

declare(strict_types=1);

namespace App\Matching;

/**
 * How well one posting fits a profile, and why.
 */
final readonly class MatchResult
{
    /**
     * @param  int  $score  0 to 100.
     * @param  string|null  $title  The wanted title that fits best, if any word of it matched.
     * @param  list<string>  $skills  Skills found in the posting.
     * @param  string|null  $excluded  The excluded word found in the title; the score is then 0.
     */
    public function __construct(
        public int $score,
        public ?string $title = null,
        public array $skills = [],
        public bool $location = false,
        public ?string $excluded = null,
    ) {
    }
}
