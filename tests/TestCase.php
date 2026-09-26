<?php

declare(strict_types=1);

namespace Tests;

use App\Net\Contracts\CertificateFetcher;
use App\Net\Contracts\DnsResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeCertificateFetcher;
use Tests\Fakes\FakeDnsResolver;

abstract class TestCase extends BaseTestCase
{
    protected FakeDnsResolver $dns;

    protected FakeCertificateFetcher $certificates;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test runs offline.
        Http::preventStrayRequests();

        $this->dns = FakeDnsResolver::fromCalibrationFixtures();
        $this->certificates = new FakeCertificateFetcher();
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->app->instance(CertificateFetcher::class, $this->certificates);
    }

    protected function fixture(string $path): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$path);
    }
}
