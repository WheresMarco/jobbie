<?php

declare(strict_types=1);

namespace App\Discovery;

/**
 * Calibrated signals for recognising a Teamtailor career site on a custom
 * domain. See README ("Kalibrering") for the method and the raw data in
 * tests/Fixtures/calibration/.
 *
 * Calibrated 2026-09-26 from DNS only. HTTP, TLS and most root TXT lookups
 * were blocked in the calibration environment; rerun radar:calibrate with
 * full network access to fill in HTML_MARKERS and the certificate notes.
 */
final class Fingerprint
{
    /**
     * Regular expressions matched against every name in a CNAME chain.
     *
     * @var array<string, string>
     */
    public const array CNAME_PATTERNS = [
        // The shared entry most customers point at. Confirmed against
        // career.kry.se, jobs.timefold.ai, jobb.nordicwellness.se,
        // jobs.lunar.app, careers.oda.com, karriar.mathem.se, career.hemnet.se,
        // jobs.tibber.com, career.anyfin.com, career.qliro.com,
        // karriar.apotea.se, jobb.clasohlson.com, jobs.storytel.com,
        // jobs.bokio.se, career.hiq.se, career.knowit.se, career.webhallen.com,
        // karriar.inet.se, jobs.dustin.se, jobs.doktor.se, jobba.capio.se,
        // careers.embark-studios.com, career.avanza.se, karriar.collector.se,
        // join.froda.se, careers.lendo.se, careers.insurely.com,
        // career.instabee.com, career.greencarrier.com, jobb.hsb.se,
        // karriar.castellum.se, jobb.vasakronan.se, jobb.apoteket.se,
        // jobb.sats.se, career.actic.se, career.pinchos.se, career.karma.life,
        // jobs.karma.life, karriar.spendrups.se and careers.telenor.se.
        'ext' => '/^ext\.teamtailor\.com$/',

        // The per account "unique entry" from Settings, Domain & SSL: a
        // 13 character lower case base 36 label under ext.teamtailor.com.
        // Confirmed against career.clasohlson.com (2f12pmplhwnk1) and
        // careers.airmee.com (3h8gokothc8iq). *.ext.teamtailor.com is a DNS
        // wildcard, so the label itself is not validated by DNS.
        'ext_unique' => '/^[0-9a-z]+\.ext\.teamtailor\.com$/',

        // Where every ext entry above points next. Matching it catches chains
        // where an intermediate name is hidden or rewritten by a DNS provider.
        // Confirmed as the second link for all hosts listed above.
        'fastly_map' => '/^teamtailor\.map\.fastly\.net$/',
    ];

    /**
     * Regular expressions matched against TXT records on the root domain.
     *
     * @var array<string, string>
     */
    public const array TXT_PATTERNS = [
        // Domain verification record. Confirmed against airmee.com
        // (teamtailor=b6ed34c0...). Only one confirmation so far because most
        // root TXT answers were too large for the calibration resolver.
        'verification' => '/^teamtailor=[0-9a-f]{32}$/',
    ];

    /**
     * Addresses of teamtailor.map.fastly.net. They are Fastly anycast
     * addresses that other Fastly customers may share, so a match is only a
     * reason to spend an HTTP request, never proof. Exact addresses rather
     * than Fastly's whole ranges keep the false positive rate down.
     * Confirmed as the A and AAAA answers for every host in CNAME_PATTERNS.
     *
     * @var list<string>
     */
    public const array IP_RANGES = [
        '151.101.1.242/32',
        '151.101.65.242/32',
        '151.101.129.242/32',
        '151.101.193.242/32',
        '2a04:4e42::498/128',
        '2a04:4e42:200::498/128',
        '2a04:4e42:400::498/128',
        '2a04:4e42:600::498/128',
    ];

    /**
     * Case insensitive substrings looked for in the HTML of "/" and in
     * response headers. Not calibrated yet: HTTP to Teamtailor was blocked
     * in the calibration environment. The CDN domain below is the working
     * hypothesis (assets., scripts. and images.teamtailor-cdn.com all exist
     * in DNS) and must be confirmed with radar:calibrate before Phase 4.
     *
     * @var array<string, string>
     */
    public const array HTML_MARKERS = [
        'cdn' => 'teamtailor-cdn.com',
    ];

    /**
     * Slug hosts. *.teamtailor.com is a DNS wildcard pointing at an AWS load
     * balancer (eu-pink-ecs-teamtailor-*.eu-west-1.elb.amazonaws.com), so DNS
     * says nothing about whether a slug exists. Slug candidates must go
     * straight to HTTP.
     */
    public const string SLUG_HOST_PATTERN = '/^[0-9a-z-]+\.teamtailor\.com$/';

    /**
     * Key of the first CNAME pattern that matches any name in the chain.
     *
     * @param  list<string>  $chain
     */
    public static function matchCname(array $chain): ?string
    {
        foreach ($chain as $name) {
            $name = strtolower(rtrim($name, '.'));
            foreach (self::CNAME_PATTERNS as $key => $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $records
     */
    public static function matchTxt(array $records): ?string
    {
        foreach ($records as $record) {
            foreach (self::TXT_PATTERNS as $key => $pattern) {
                if (preg_match($pattern, trim($record)) === 1) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * The first range containing any of the addresses.
     *
     * @param  list<string>  $addresses
     */
    public static function matchAddress(array $addresses): ?string
    {
        foreach ($addresses as $address) {
            foreach (self::IP_RANGES as $range) {
                if (self::inRange($address, $range)) {
                    return $range;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public static function matchHtml(string $html, array $headers = []): ?string
    {
        $haystack = strtolower($html);
        foreach ($headers as $name => $values) {
            $haystack .= "\n".strtolower($name).': '.strtolower(implode(', ', (array) $values));
        }

        foreach (self::HTML_MARKERS as $key => $marker) {
            if (str_contains($haystack, strtolower($marker))) {
                return $key;
            }
        }

        return null;
    }

    public static function isSlugHost(string $host): bool
    {
        return preg_match(self::SLUG_HOST_PATTERN, strtolower($host)) === 1
            && ! str_ends_with(strtolower($host), '.ext.teamtailor.com');
    }

    private static function inRange(string $address, string $range): bool
    {
        [$network, $bits] = array_pad(explode('/', $range, 2), 2, null);

        $addressBytes = @inet_pton($address);
        $networkBytes = @inet_pton((string) $network);

        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $bits = $bits === null ? strlen($addressBytes) * 8 : (int) $bits;
        $fullBytes = intdiv($bits, 8);

        if (strncmp($addressBytes, $networkBytes, $fullBytes) !== 0) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}
