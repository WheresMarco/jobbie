<?php

declare(strict_types=1);

namespace App\Net;

use Pdp\Rules;
use Throwable;

/**
 * Host normalisation and registrable domain lookup based on the Public
 * Suffix List. Normalised hosts are lower case ASCII (Punycode) without a
 * trailing dot, port or path.
 */
final readonly class DomainName
{
    private const int MAX_LENGTH = 253;

    private const string LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(private Rules $rules)
    {
    }

    /**
     * Normalise a host or URL. Returns null for anything that is not a
     * public DNS name with at least two labels, including IP addresses.
     */
    public function normalize(string $input): ?string
    {
        $host = trim($input);
        if ($host === '') {
            return null;
        }

        if (str_contains($host, '://')) {
            $host = parse_url($host, PHP_URL_HOST);
            if (! is_string($host)) {
                return null;
            }
        } else {
            $host = (string) preg_replace('~[/?#].*$~s', '', $host);
            $host = (string) preg_replace('~^.*@~', '', $host);
            $host = (string) preg_replace('~:\d+$~', '', $host);
        }

        $host = rtrim(mb_strtolower($host), '.');
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $host = $ascii;
        }

        return self::isValidAscii($host) ? $host : null;
    }

    /**
     * The registrable domain (public suffix plus one label), for example
     * kry.se for career.kry.se and foretag.co.uk for jobs.foretag.co.uk.
     * Null when the input is invalid or is itself a public suffix.
     */
    public function registrable(string $input): ?string
    {
        $host = $this->normalize($input);
        if ($host === null) {
            return null;
        }

        try {
            $domain = $this->rules->resolve($host);
        } catch (Throwable) {
            return null;
        }

        if (! $domain->suffix()->isKnown()) {
            return null;
        }

        return $domain->registrableDomain()->toString() ?: null;
    }

    /**
     * Human readable (Unicode) form of a normalised host.
     */
    public function toUnicode(string $host): string
    {
        $unicode = idn_to_utf8($host, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

        return $unicode === false ? $host : $unicode;
    }

    private static function isValidAscii(string $host): bool
    {
        if (strlen($host) > self::MAX_LENGTH) {
            return false;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                return false;
            }
        }

        // The top level domain is never all digits.
        return ! ctype_digit($labels[array_key_last($labels)]);
    }
}
