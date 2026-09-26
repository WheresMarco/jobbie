<?php

declare(strict_types=1);

namespace App\Net;

use JsonSerializable;

final readonly class CertificateInfo implements JsonSerializable
{
    /**
     * @param  list<string>  $subjectAltNames  DNS names from the SAN extension.
     */
    public function __construct(
        public ?string $subject,
        public ?string $issuerOrganization,
        public ?string $issuerCommonName,
        public array $subjectAltNames,
        public ?int $validFrom,
        public ?int $validTo,
    ) {
    }

    /**
     * Build from the array returned by openssl_x509_parse().
     *
     * @param  array<string, mixed>  $parsed
     */
    public static function fromOpenSsl(array $parsed): self
    {
        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $issuer = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];
        $extensions = is_array($parsed['extensions'] ?? null) ? $parsed['extensions'] : [];

        $sans = [];
        if (is_string($extensions['subjectAltName'] ?? null)) {
            foreach (explode(',', $extensions['subjectAltName']) as $entry) {
                $entry = trim($entry);
                if (str_starts_with($entry, 'DNS:')) {
                    $sans[] = strtolower(substr($entry, 4));
                }
            }
        }

        return new self(
            subject: self::stringOrNull($subject['CN'] ?? null),
            issuerOrganization: self::stringOrNull($issuer['O'] ?? null),
            issuerCommonName: self::stringOrNull($issuer['CN'] ?? null),
            subjectAltNames: $sans,
            validFrom: is_int($parsed['validFrom_time_t'] ?? null) ? $parsed['validFrom_time_t'] : null,
            validTo: is_int($parsed['validTo_time_t'] ?? null) ? $parsed['validTo_time_t'] : null,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = end($value);
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'subject' => $this->subject,
            'issuer_organization' => $this->issuerOrganization,
            'issuer_common_name' => $this->issuerCommonName,
            'subject_alt_names' => $this->subjectAltNames,
            'valid_from' => $this->validFrom === null ? null : gmdate('c', $this->validFrom),
            'valid_to' => $this->validTo === null ? null : gmdate('c', $this->validTo),
        ];
    }
}
