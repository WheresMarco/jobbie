<?php

declare(strict_types=1);

namespace App\Discovery\Calibration;

use App\Discovery\Fingerprint;
use App\Net\CertificateInfo;
use App\Net\DnsAnswer;
use JsonSerializable;

final readonly class CalibrationResult implements JsonSerializable
{
    /**
     * @param  list<HttpSnapshot>  $http
     * @param  array<string, string>  $errors  Section name => message.
     */
    public function __construct(
        public string $host,
        public string $rootDomain,
        public string $capturedAt,
        public ?DnsAnswer $a = null,
        public ?DnsAnswer $aaaa = null,
        public ?DnsAnswer $rootTxt = null,
        public ?CertificateInfo $certificate = null,
        public array $http = [],
        public array $errors = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function cnameChain(): array
    {
        return $this->a->cnameChain ?? $this->aaaa->cnameChain ?? [];
    }

    public function cnameMatch(): ?string
    {
        return Fingerprint::matchCname($this->cnameChain());
    }

    public function addressMatch(): ?string
    {
        return Fingerprint::matchAddress([...$this->a->values ?? [], ...$this->aaaa->values ?? []]);
    }

    public function txtMatch(): ?string
    {
        return $this->rootTxt === null ? null : Fingerprint::matchTxt($this->rootTxt->values);
    }

    public function snapshot(string $path): ?HttpSnapshot
    {
        foreach ($this->http as $snapshot) {
            if (parse_url($snapshot->url, PHP_URL_PATH) === $path) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'host' => $this->host,
            'root_domain' => $this->rootDomain,
            'captured_at' => $this->capturedAt,
            'dns' => [
                'a' => $this->a,
                'aaaa' => $this->aaaa,
                'root_txt' => $this->rootTxt,
            ],
            'tls' => $this->certificate,
            'http' => $this->http,
            'matches' => [
                'cname' => $this->cnameMatch(),
                'address' => $this->addressMatch(),
                'txt' => $this->txtMatch(),
            ],
            'errors' => $this->errors,
        ];
    }
}
