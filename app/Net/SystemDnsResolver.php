<?php

declare(strict_types=1);

namespace App\Net;

use App\Net\Contracts\DnsResolver;

/**
 * Resolver using the operating system through dns_get_record(). It cannot
 * tell NXDOMAIN from an empty answer, has no timeout control and walks the
 * CNAME chain one query at a time, so it is only meant as a fallback where
 * DNS over HTTPS is unavailable.
 */
final class SystemDnsResolver implements DnsResolver
{
    private const int MAX_CHAIN = 16;

    public function resolve(string $host, DnsRecordType $type): DnsAnswer
    {
        $chain = [];
        $name = strtolower(rtrim($host, '.'));

        while (count($chain) < self::MAX_CHAIN) {
            $records = @dns_get_record($name, DNS_CNAME);
            if (! is_array($records) || ! is_string($records[0]['target'] ?? null)) {
                break;
            }

            $target = strtolower(rtrim($records[0]['target'], '.'));
            if ($target === $name || in_array($target, $chain, true)) {
                break;
            }

            $chain[] = $name = $target;
        }

        if ($type === DnsRecordType::CNAME) {
            return new DnsAnswer($host, $type, $chain === [] ? DnsStatus::NoData : DnsStatus::Ok, $chain, $chain);
        }

        $records = @dns_get_record($name, $type->phpConstant());
        if ($records === false) {
            return DnsAnswer::failed($host, $type, 'dns_get_record() failed');
        }

        $values = [];
        foreach ($records as $record) {
            $value = match ($type) {
                DnsRecordType::A => $record['ip'] ?? null,
                DnsRecordType::AAAA => $record['ipv6'] ?? null,
                // Only TXT remains, CNAME returned above.
                default => isset($record['entries']) && is_array($record['entries'])
                    ? implode('', $record['entries'])
                    : ($record['txt'] ?? null),
            };

            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return new DnsAnswer($host, $type, $values === [] ? DnsStatus::NoData : DnsStatus::Ok, $chain, $values);
    }

    public function resolveMany(array $hosts, DnsRecordType $type): array
    {
        $answers = [];
        foreach (array_unique($hosts) as $host) {
            $answers[$host] = $this->resolve($host, $type);
        }

        return $answers;
    }
}
