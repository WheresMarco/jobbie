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
    'stats' => [
        'sources' => 'Upptäcktskällor',
        'boards' => 'Karriärsidor',
        'in_platsbanken' => 'Finns även i Platsbanken',
        'active_postings' => 'Aktiva annonser',
        'prefix_hits' => 'Verifierade per prefix',
        'txt_without_board' => 'Kunder enligt TXT utan hittad sida',
        'columns' => [
            'source' => 'Källa',
            'tested' => 'Testade',
            'active_boards' => 'Aktiva sidor',
            'postings' => 'Annonser',
        ],
    ],
    'stages' => [
        'new' => 'Nya',
        'no_dns' => 'Utan DNS',
        'dns_ok' => 'DNS ok',
        'fingerprint_match' => 'Fingeravtryck',
        'verified' => 'Verifierade',
        'rejected' => 'Avvisade',
    ],
    'sources' => [
        'seed' => 'Startlista',
        'redirect' => 'Omdirigeringar (A)',
        'jobstream' => 'Platsbanken (B)',
        'prefix' => 'Prefix (C)',
        'commoncrawl' => 'Common Crawl (D)',
        'txt' => 'TXT (E)',
        'http_archive' => 'HTTP Archive (F)',
        'search_api' => 'Sök-API (G)',
    ],
    'kinds' => [
        'tt_subdomain' => 'Teamtailor-adress',
        'custom' => 'Egen domän',
    ],
    'statuses' => [
        'new' => 'Ny',
        'active' => 'Aktiv',
        'empty' => 'Tom',
        'dead' => 'Död',
        'error' => 'Fel',
    ],
    'remote' => [
        'fully' => 'Distans',
        'hybrid' => 'Hybrid',
        'onsite' => 'På plats',
        'unknown' => 'Ej angivet',
    ],
    'match_platsbanken' => [
        'done' => ':matched av :active aktiva annonser (:share %) finns även i Platsbanken: :apply_url via ansökningslänk, :org_title via organisationsnummer och titel.',
    ],
    'user' => [
        'invalid_email' => '”:email” är ingen giltig e-postadress.',
        'password' => 'Lösenord',
        'password_again' => 'Lösenord igen',
        'too_short' => 'Lösenordet måste ha minst :min tecken.',
        'mismatch' => 'Lösenorden stämmer inte överens.',
        'created' => 'Kontot :email är skapat.',
        'updated' => 'Nytt lösenord för :email är sparat.',
    ],
    'smoke' => [
        'columns' => [
            'host' => 'Värd',
            'stage' => 'Steg',
            'reason' => 'Orsak',
            'final_host' => 'Slutvärd',
            'postings' => 'Annonser',
            'rss' => 'RSS-poster',
        ],
    ],
];
