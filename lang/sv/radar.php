<?php

declare(strict_types=1);

return [
    'calibrate' => [
        'examining' => 'Undersöker :host',
        'written' => 'Resultatet sparades i :directory',
        'unknown_section' => 'Okänd del: :section. Giltiga värden är dns, txt, tls och http.',
        'feed_error' => 'fel',
        'columns' => [
            'host' => 'Värd',
            'cname' => 'CNAME-kedja',
            'cname_match' => 'CNAME-träff',
            'address_match' => 'IP-träff',
            'txt_match' => 'TXT-träff',
            'feed' => 'jobs.json',
            'final_host' => 'Slutvärd',
        ],
    ],
];
