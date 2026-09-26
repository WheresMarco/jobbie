<?php

declare(strict_types=1);

return [
    'app_name' => 'Teamtailor radar',
    'nav' => [
        'jobs' => 'Jobs',
        'stats' => 'Statistics',
    ],
    'jobs' => [
        'search_placeholder' => 'Search title, employer or text',
        'view' => 'View',
        'view_grouped' => 'By employer',
        'view_flat' => 'All postings',
        'count' => '{0} No postings|{1} :count posting|[2,*] :count postings',
        'loading' => 'Loading …',
        'empty_database' => 'No postings yet.',
        'empty_database_hint' => 'Run radar:discover, radar:verify and radar:scrape to fill the database.',
        'empty_filters' => 'No postings match the filters.',
        'remote_count' => '{1} :count remote|[2,*] :count remote',
        'career_site' => 'Career site',
        'more_in_group' => 'and :count more from the same employer',
        'show_more' => 'Show more',
        'new_badge' => 'New',
    ],
    'filters' => [
        'title' => 'Filters',
        'clear' => 'Clear filters',
        'all' => 'All',
        'country' => 'Country',
        'city' => 'City',
        'remote' => 'Workplace',
        'department' => 'Department',
        'published' => 'Published',
        'any_time' => 'Any time',
        'within_days' => '{1} Last 24 hours|[2,*] Last :days days',
        'new' => 'Discovered in the last :days days',
        'kind' => 'Career site',
    ],
    'remote' => [
        'fully' => 'Remote',
        'hybrid' => 'Hybrid',
        'onsite' => 'On site',
        'unknown' => 'Not stated',
    ],
    'stats' => [
        'sources' => 'Discovery sources',
        'boards' => 'Career sites',
        'by_kind' => 'By kind',
        'by_status' => 'By status',
        'active_postings' => 'Active postings',
        'prefix_hits' => 'Verified career sites per prefix',
        'txt_without_board' => 'Customers by TXT without a found career site',
        'none' => 'None yet.',
    ],
];
