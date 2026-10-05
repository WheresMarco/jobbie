<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Counts per filter option, each computed with all other filters applied.
 */
final readonly class Facets
{
    /**
     * @param  array<string, int>  $countries  ISO code => postings
     * @param  array<string, int>  $cities
     * @param  array<string, int>  $remote  JobFilters::REMOTE_OPTIONS => postings
     * @param  array<string, int>  $departments
     * @param  array<int, int>  $published  days => postings
     * @param  array<string, int>  $kinds  BoardKind value => postings
     * @param  int  $unseen  Postings the user has not seen
     */
    public function __construct(
        public array $countries,
        public array $cities,
        public array $remote,
        public array $departments,
        public array $published,
        public int $new,
        public array $kinds,
        public int $unseen = 0,
    ) {
    }
}
