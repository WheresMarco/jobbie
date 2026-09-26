<?php

declare(strict_types=1);

namespace App\Net\Contracts;

use App\Net\DnsAnswer;
use App\Net\DnsRecordType;

interface DnsResolver
{
    /**
     * Resolve one name. Implementations never throw on DNS or transport
     * failures; they return an answer with status Error instead.
     */
    public function resolve(string $host, DnsRecordType $type): DnsAnswer;

    /**
     * Resolve many names of the same type, concurrently where supported.
     *
     * @param  list<string>  $hosts
     * @return array<string, DnsAnswer> Keyed by host.
     */
    public function resolveMany(array $hosts, DnsRecordType $type): array;
}
