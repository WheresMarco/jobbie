<?php

declare(strict_types=1);

return [

    /*
    | HTTP client settings shared by discovery, verification and scraping.
    */
    'user_agent' => env('RADAR_USER_AGENT', 'TeamtailorRadar/0.1 (+https://github.com/wheresmarco/jobbie)'),
    'http_timeout' => (int) env('RADAR_HTTP_TIMEOUT', 15),
    'http_pool_size' => (int) env('RADAR_HTTP_POOL_SIZE', 10),

    /*
    | DNS. The "doh" driver queries a DNS over HTTPS JSON endpoint and is the
    | default. The "system" driver uses the operating system resolver through
    | dns_get_record(), which is handy where outbound DoH is blocked, but it has
    | no timeout control and large TXT answers can hang on TCP fallback.
    */
    'dns_driver' => env('RADAR_DNS_DRIVER', 'doh'),
    'doh_endpoint' => env('RADAR_DOH_ENDPOINT', 'https://cloudflare-dns.com/dns-query'),
    'dns_timeout' => (int) env('RADAR_DNS_TIMEOUT', 5),
    'dns_pool_size' => (int) env('RADAR_DNS_POOL_SIZE', 50),

    /*
    | TLS probing used by radar:calibrate.
    */
    'tls_timeout' => (int) env('RADAR_TLS_TIMEOUT', 10),

    /*
    | Subdomain prefixes tried against known base domains (source C).
    */
    'prefixes' => [
        'career', 'careers', 'karriar', 'xn--karrir-fua', 'jobb', 'jobs', 'join', 'jobba',
        'work', 'arbeta', 'rekrytering', 'lediga-jobb', 'talent', 'people', 'team',
    ],

    /*
    | Prefixes tried on domains whose root TXT record shows they are
    | Teamtailor customers (source E). Wider than the list above, since a
    | career site is known to exist somewhere under the domain.
    */
    'txt_prefixes' => [
        'career', 'careers', 'karriar', 'xn--karrir-fua', 'jobb', 'jobs', 'join', 'jobba', 'work',
        'arbeta', 'arbetamedoss', 'rekrytering', 'recruit', 'recruiting', 'lediga-jobb', 'ledigajobb',
        'talent', 'people', 'team', 'hr', 'apply', 'ansok', 'hiring', 'jobbahososs', 'jobbamedoss',
    ],
    'txt_recheck_days' => (int) env('RADAR_TXT_RECHECK_DAYS', 30),

    'dead_recheck_days' => (int) env('RADAR_DEAD_RECHECK_DAYS', 30),
    'rejected_recheck_days' => (int) env('RADAR_REJECTED_RECHECK_DAYS', 60),
    'new_job_days' => (int) env('RADAR_NEW_JOB_DAYS', 7),

    'sources' => [
        'enabled' => array_values(array_filter(explode(',', (string) env(
            'RADAR_SOURCES',
            'redirects,jobstream,prefixes,commoncrawl',
        )))),
    ],

    'jobstream' => [
        'base_url' => env('RADAR_JOBSTREAM_BASE_URL', 'https://jobstream.api.jobtechdev.se'),
        'api_key' => env('RADAR_JOBSTREAM_API_KEY'),
    ],

    'commoncrawl' => [
        'index_count' => (int) env('RADAR_COMMONCRAWL_INDEXES', 3),
    ],

    /*
    | Source G, off by default: Brave Search API with the key from .env.
    */
    'search_api' => [
        'key' => env('RADAR_SEARCH_API_KEY'),
        'endpoint' => env('RADAR_SEARCH_API_ENDPOINT', 'https://api.search.brave.com/res/v1/web/search'),
        'pages' => (int) env('RADAR_SEARCH_API_PAGES', 5),
        'queries' => [
            '"teamtailor" lediga jobb',
            '"teamtailor" karriär',
            '"powered by teamtailor"',
            '"teamtailor" jobba hos oss',
        ],
    ],

    /*
    | Source F, off by default: HTTP Archive through BigQuery, billed to your
    | own Google Cloud project. Credentials are a service account JSON file.
    */
    'http_archive' => [
        'google_project' => env('RADAR_HTTP_ARCHIVE_PROJECT'),
        'google_credentials' => env('RADAR_HTTP_ARCHIVE_CREDENTIALS'),
        'crawl_date' => env('RADAR_HTTP_ARCHIVE_DATE'),
        'max_bytes_billed' => (int) env('RADAR_HTTP_ARCHIVE_MAX_BYTES', 200 * 1024 ** 3),
    ],

    'basic_auth' => [
        'user' => env('RADAR_BASIC_AUTH_USER'),
        'password' => env('RADAR_BASIC_AUTH_PASSWORD'),
    ],

];
