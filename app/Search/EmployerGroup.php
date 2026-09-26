<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\JobPosting;

/**
 * Postings of one employer. Grouping is on the posting's employer, not the
 * career site, because staffing agencies post on behalf of clients.
 */
final readonly class EmployerGroup
{
    /**
     * @param  list<JobPosting>  $postings  The newest postings (at most JobSearch::POSTINGS_PER_GROUP), loaded only for open groups.
     * @param  list<string>  $cities  Most common cities first.
     */
    public function __construct(
        public string $employer,
        public int $total,
        public int $remote,
        public array $postings,
        public array $cities,
        public ?string $siteUrl,
        public bool $open = false,
    ) {
    }
}
