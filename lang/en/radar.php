<?php

declare(strict_types=1);

return [
    'calibrate' => [
        'examining' => 'Examining :host',
        'written' => 'Results written to :directory',
        'unknown_section' => 'Unknown section: :section. Valid values are dns, txt, tls and http.',
        'feed_error' => 'error',
        'columns' => [
            'host' => 'Host',
            'cname' => 'CNAME chain',
            'cname_match' => 'CNAME match',
            'address_match' => 'IP match',
            'txt_match' => 'TXT match',
            'feed' => 'jobs.json',
            'final_host' => 'Final host',
        ],
    ],
    'psl' => [
        'updated' => 'Public Suffix List updated (:bytes bytes) at :path',
        'failed' => 'Could not update the Public Suffix List: :error',
    ],
    'import_legacy' => [
        'missing' => 'The file :path does not exist.',
        'unreadable' => 'Could not read the database: :error',
        'no_columns' => 'Found no table with a host or URL column.',
        'done' => ':count new candidates imported.',
        'columns' => [
            'column' => 'Column',
            'seen' => 'Values',
            'invalid' => 'Invalid',
            'created' => 'New',
        ],
    ],
    'scrape' => [
        'nothing' => 'No career sites to scrape.',
        'dispatched' => ':count career sites queued (batch :batch).',
        'columns' => [
            'status' => 'Status',
            'boards' => 'Career sites',
            'jobs' => 'Postings',
        ],
    ],
    'verify' => [
        'nothing' => 'No candidates to verify.',
        'dispatched' => ':count candidates queued in :batches batches.',
        'columns' => [
            'stage' => 'Stage',
            'candidates' => 'Candidates',
        ],
    ],
    'discover' => [
        'unknown_source' => 'Unknown source: :source.',
        'implicit' => 'The :source source runs automatically when scraping.',
        'queued' => 'Source :source queued.',
        'collected' => 'Source :source gave :seen hosts, :created new candidates (:invalid invalid).',
        'jobstream_synced' => 'JobStream: :ads ads, :removed removed, :domains new domains.',
        'domains_imported' => ':count new domains imported.',
    ],
    'stats' => [
        'sources' => 'Discovery sources',
        'boards' => 'Career sites',
        'in_platsbanken' => 'Also in Platsbanken',
        'active_postings' => 'Active postings',
        'prefix_hits' => 'Verified per prefix',
        'txt_without_board' => 'Customers by TXT without a found site',
        'columns' => [
            'source' => 'Source',
            'tested' => 'Tested',
            'active_boards' => 'Active sites',
            'postings' => 'Postings',
        ],
    ],
    'stages' => [
        'new' => 'New',
        'no_dns' => 'No DNS',
        'dns_ok' => 'DNS ok',
        'fingerprint_match' => 'Fingerprint',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
    ],
    'sources' => [
        'seed' => 'Seed list',
        'redirect' => 'Redirects (A)',
        'jobstream' => 'Platsbanken (B)',
        'prefix' => 'Prefixes (C)',
        'commoncrawl' => 'Common Crawl (D)',
        'txt' => 'TXT (E)',
        'http_archive' => 'HTTP Archive (F)',
        'search_api' => 'Search API (G)',
    ],
    'kinds' => [
        'tt_subdomain' => 'Teamtailor address',
        'custom' => 'Custom domain',
    ],
    'statuses' => [
        'new' => 'New',
        'active' => 'Active',
        'empty' => 'Empty',
        'dead' => 'Dead',
        'error' => 'Error',
    ],
    'remote' => [
        'fully' => 'Remote',
        'hybrid' => 'Hybrid',
        'onsite' => 'On site',
        'unknown' => 'Not stated',
    ],
    'match_platsbanken' => [
        'done' => ':matched of :active active postings (:share %) are also in Platsbanken: :apply_url by apply link, :org_title by organisation number and title.',
    ],
    'admin' => [
        'not_found' => 'There is no account with the address :email.',
        'granted' => ':email is now an admin.',
        'revoked' => ':email is no longer an admin.',
    ],
    'user' => [
        'invalid_email' => '":email" is not a valid email address.',
        'password' => 'Password',
        'password_again' => 'Password again',
        'too_short' => 'The password needs at least :min characters.',
        'mismatch' => 'The passwords do not match.',
        'created' => 'Account :email created.',
        'updated' => 'New password for :email saved.',
    ],
    'smoke' => [
        'columns' => [
            'host' => 'Host',
            'stage' => 'Stage',
            'reason' => 'Reason',
            'final_host' => 'Final host',
            'postings' => 'Postings',
            'rss' => 'RSS items',
        ],
    ],
];
