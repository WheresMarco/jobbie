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
    'psl' => [
        'updated' => 'Public Suffix List uppdaterad (:bytes byte) i :path',
        'failed' => 'Kunde inte uppdatera Public Suffix List: :error',
    ],
    'import_legacy' => [
        'missing' => 'Filen :path finns inte.',
        'unreadable' => 'Kunde inte läsa databasen: :error',
        'no_columns' => 'Hittade ingen tabell med en kolumn för värd eller adress.',
        'done' => ':count nya kandidater importerades.',
        'columns' => [
            'column' => 'Kolumn',
            'seen' => 'Värden',
            'invalid' => 'Ogiltiga',
            'created' => 'Nya',
        ],
    ],
    'scrape' => [
        'nothing' => 'Inga karriärsidor att skrapa.',
        'dispatched' => ':count karriärsidor köade (batch :batch).',
        'columns' => [
            'status' => 'Status',
            'boards' => 'Karriärsidor',
            'jobs' => 'Annonser',
        ],
    ],
    'verify' => [
        'nothing' => 'Inga kandidater att verifiera.',
        'dispatched' => ':count kandidater köade i :batches batchar.',
        'columns' => [
            'stage' => 'Steg',
            'candidates' => 'Kandidater',
        ],
    ],
    'discover' => [
        'unknown_source' => 'Okänd källa: :source.',
        'implicit' => 'Källan :source körs automatiskt vid skrapning.',
        'queued' => 'Källan :source köad.',
        'collected' => 'Källan :source gav :seen värdar, :created nya kandidater (:invalid ogiltiga).',
        'jobstream_synced' => 'JobStream: :ads annonser, :removed borttagna, :domains nya domäner.',
        'domains_imported' => ':count nya domäner importerades.',
    ],
];
