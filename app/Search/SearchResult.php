<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchResult
{
    /**
     * @param  list<EmployerGroup>  $groups
     * @param  list<\App\Models\JobPosting>  $postings
     */
    public function __construct(
        public int $total,
        public Facets $facets,
        public array $groups,
        public array $postings,
        public bool $hasMore,
    ) {
    }
}
