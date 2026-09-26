<?php

declare(strict_types=1);

namespace App\Net;

use JsonSerializable;

final readonly class DnsAnswer implements JsonSerializable
{
    /**
     * @param  list<string>  $cnameChain  CNAME targets in resolution order, not including the queried host.
     * @param  list<string>  $values  Record values of the requested type at the end of the chain.
     */
    public function __construct(
        public string $host,
        public DnsRecordType $type,
        public DnsStatus $status,
        public array $cnameChain = [],
        public array $values = [],
        public ?string $error = null,
    ) {
    }

    public static function failed(string $host, DnsRecordType $type, string $error): self
    {
        return new self($host, $type, DnsStatus::Error, error: $error);
    }

    /**
     * The name the chain ends at, which is where the records live.
     */
    public function finalName(): string
    {
        return $this->cnameChain === [] ? $this->host : $this->cnameChain[array_key_last($this->cnameChain)];
    }

    /**
     * @return array{host: string, type: string, status: string, cname_chain: list<string>, values: list<string>, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'type' => $this->type->name,
            'status' => $this->status->value,
            'cname_chain' => $this->cnameChain,
            'values' => $this->values,
            'error' => $this->error,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? 'A';
        $status = $data['status'] ?? 'error';

        return new self(
            host: is_string($data['host'] ?? null) ? $data['host'] : '',
            type: DnsRecordType::fromName(is_string($type) ? $type : 'A'),
            status: DnsStatus::from(is_string($status) ? $status : 'error'),
            cnameChain: array_values(array_map(strval(...), (array) ($data['cname_chain'] ?? []))),
            values: array_values(array_map(strval(...), (array) ($data['values'] ?? []))),
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
