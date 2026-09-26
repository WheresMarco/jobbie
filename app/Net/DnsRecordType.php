<?php

declare(strict_types=1);

namespace App\Net;

/**
 * DNS record types used by the radar. Values are the IANA type codes, which
 * is also what DNS over HTTPS JSON answers use in their "type" field.
 */
enum DnsRecordType: int
{
    case A = 1;
    case CNAME = 5;
    case TXT = 16;
    case AAAA = 28;

    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if ($case->name === strtoupper($name)) {
                return $case;
            }
        }

        throw new \ValueError("Unknown DNS record type [{$name}].");
    }

    /**
     * The matching constant for dns_get_record().
     */
    public function phpConstant(): int
    {
        return match ($this) {
            self::A => DNS_A,
            self::CNAME => DNS_CNAME,
            self::TXT => DNS_TXT,
            self::AAAA => DNS_AAAA,
        };
    }
}
