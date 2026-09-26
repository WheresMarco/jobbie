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
];
