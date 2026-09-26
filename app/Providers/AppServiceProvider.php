<?php

declare(strict_types=1);

namespace App\Providers;

use App\Discovery\Calibration\Calibrator;
use App\Net\Contracts\CertificateFetcher;
use App\Net\Contracts\DnsResolver;
use App\Net\DohDnsResolver;
use App\Net\DomainName;
use App\Net\PublicSuffixList;
use App\Net\StreamCertificateFetcher;
use App\Net\SystemDnsResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DnsResolver::class, static fn (): DnsResolver => match (config('radar.dns_driver')) {
            'system' => new SystemDnsResolver(),
            default => new DohDnsResolver(
                endpoint: config()->string('radar.doh_endpoint'),
                timeout: config()->integer('radar.dns_timeout'),
                poolSize: config()->integer('radar.dns_pool_size'),
                userAgent: config()->string('radar.user_agent'),
            ),
        });

        $this->app->singleton(DomainName::class, static fn (): DomainName => new DomainName(PublicSuffixList::rules()));

        $this->app->singleton(CertificateFetcher::class, static fn (): CertificateFetcher => new StreamCertificateFetcher(
            timeout: config()->integer('radar.tls_timeout'),
        ));

        $this->app->bind(Calibrator::class, static fn (Application $app): Calibrator => new Calibrator(
            dns: $app->make(DnsResolver::class),
            certificates: $app->make(CertificateFetcher::class),
            domains: $app->make(DomainName::class),
            userAgent: config()->string('radar.user_agent'),
            httpTimeout: config()->integer('radar.http_timeout'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
