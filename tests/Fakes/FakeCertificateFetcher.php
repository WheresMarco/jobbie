<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Net\CertificateInfo;
use App\Net\Contracts\CertificateFetcher;
use RuntimeException;

final class FakeCertificateFetcher implements CertificateFetcher
{
    /** @var array<string, CertificateInfo> */
    private array $certificates = [];

    public function add(string $host, CertificateInfo $certificate): self
    {
        $this->certificates[$host] = $certificate;

        return $this;
    }

    public function fetch(string $host): CertificateInfo
    {
        return $this->certificates[$host] ?? throw new RuntimeException("No certificate for {$host}");
    }
}
