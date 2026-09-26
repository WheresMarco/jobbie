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
];
