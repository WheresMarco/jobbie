<?php

declare(strict_types=1);

namespace App\Net\Contracts;

use App\Net\CertificateInfo;
use RuntimeException;

interface CertificateFetcher
{
    /**
     * Fetch the certificate a host presents on port 443 for its own name (SNI).
     *
     * @throws RuntimeException When no certificate could be read.
     */
    public function fetch(string $host): CertificateInfo;
}
