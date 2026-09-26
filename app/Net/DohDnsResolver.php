<?php

declare(strict_types=1);

namespace App\Net;

use App\Net\Contracts\DnsResolver;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolver backed by the DNS over HTTPS JSON API (Cloudflare and Google
 * flavour). A single query returns the whole CNAME chain together with the
 * final records, which is exactly what fingerprinting needs.
 */
final readonly class DohDnsResolver implements DnsResolver
{
    private const int RCODE_NOERROR = 0;

    private const int RCODE_NXDOMAIN = 3;

    public function __construct(
        private string $endpoint,
        private int $timeout,
        private int $poolSize,
        private string $userAgent,
    ) {
    }

    public function resolve(string $host, DnsRecordType $type): DnsAnswer
    {
        try {
            $response = $this->request($host, $type);
        } catch (Throwable $e) {
            return DnsAnswer::failed($host, $type, $e->getMessage());
        }

        return $this->parse($host, $type, $response);
    }

    public function resolveMany(array $hosts, DnsRecordType $type): array
    {
        $answers = [];

        foreach (array_chunk(array_values(array_unique($hosts)), max(1, $this->poolSize)) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $type): array {
                $requests = [];
                foreach ($chunk as $index => $host) {
                    $requests[] = $pool->as((string) $index)
                        ->withUserAgent($this->userAgent)
                        ->accept('application/dns-json')
                        ->timeout($this->timeout)
                        ->get($this->endpoint, ['name' => $host, 'type' => $type->name]);
                }

                return $requests;
            });

            foreach ($chunk as $index => $host) {
                $response = $responses[(string) $index] ?? null;
                $answers[$host] = $response instanceof Response
                    ? $this->parse($host, $type, $response)
                    : DnsAnswer::failed($host, $type, $response instanceof Throwable ? $response->getMessage() : 'No response');
            }
        }

        return $answers;
    }

    private function request(string $host, DnsRecordType $type): Response
    {
        return Http::withUserAgent($this->userAgent)
            ->accept('application/dns-json')
            ->timeout($this->timeout)
            ->get($this->endpoint, ['name' => $host, 'type' => $type->name]);
    }

    private function parse(string $host, DnsRecordType $type, Response $response): DnsAnswer
    {
        if (! $response->successful()) {
            return DnsAnswer::failed($host, $type, 'HTTP '.$response->status());
        }

        $body = $response->json();
        if (! is_array($body) || ! is_int($body['Status'] ?? null)) {
            return DnsAnswer::failed($host, $type, 'Malformed DoH response');
        }

        if ($body['Status'] === self::RCODE_NXDOMAIN) {
            return new DnsAnswer($host, $type, DnsStatus::NxDomain);
        }

        if ($body['Status'] !== self::RCODE_NOERROR) {
            return DnsAnswer::failed($host, $type, 'RCODE '.$body['Status']);
        }

        /** @var array<string, string> $cnames owner name => target */
        $cnames = [];
        /** @var array<string, list<string>> $records owner name => values */
        $records = [];

        foreach (is_array($body['Answer'] ?? null) ? $body['Answer'] : [] as $record) {
            if (! is_array($record) || ! is_string($record['name'] ?? null) || ! is_string($record['data'] ?? null)) {
                continue;
            }

            $name = self::normalize($record['name']);
            $recordType = $record['type'] ?? null;

            if ($recordType === DnsRecordType::CNAME->value) {
                $cnames[$name] = self::normalize($record['data']);
            } elseif ($recordType === $type->value) {
                $records[$name][] = $type === DnsRecordType::TXT
                    ? self::txtValue($record['data'])
                    : self::normalize($record['data']);
            }
        }

        $chain = [];
        $name = self::normalize($host);
        while (isset($cnames[$name]) && ! in_array($cnames[$name], $chain, true) && count($chain) < 16) {
            $name = $cnames[$name];
            $chain[] = $name;
        }

        if ($type === DnsRecordType::CNAME) {
            return new DnsAnswer($host, $type, $chain === [] ? DnsStatus::NoData : DnsStatus::Ok, $chain, $chain);
        }

        $values = $records[$name] ?? [];

        return new DnsAnswer($host, $type, $values === [] ? DnsStatus::NoData : DnsStatus::Ok, $chain, $values);
    }

    private static function normalize(string $name): string
    {
        return strtolower(rtrim(trim($name), '.'));
    }

    /**
     * TXT data arrives as one or more quoted character strings, for example
     * "v=spf1 include:a" " -all". The strings are concatenated.
     */
    private static function txtValue(string $data): string
    {
        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $data, $matches) > 0) {
            return stripcslashes(implode('', $matches[1]));
        }

        return $data;
    }
}
