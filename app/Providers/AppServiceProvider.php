<?php

declare(strict_types=1);

namespace App\Providers;

use App\Discovery\Calibration\Calibrator;
use App\Discovery\JobStream\JobStreamSync;
use App\Discovery\Sources\CommonCrawlSource;
use App\Discovery\Sources\PrefixSource;
use App\Discovery\Verifier;
use App\Net\Contracts\CertificateFetcher;
use App\Net\Contracts\DnsResolver;
use App\Net\DohDnsResolver;
use App\Net\DomainName;
use App\Net\PublicSuffixList;
use App\Net\StreamCertificateFetcher;
use App\Net\SystemDnsResolver;
use App\Scraping\FeedClient;
use App\Scraping\RssEnricher;
use App\Search\JobSearch;
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

        $this->app->bind(FeedClient::class, static fn (): FeedClient => new FeedClient(
            userAgent: config()->string('radar.user_agent'),
            timeout: config()->integer('radar.http_timeout'),
        ));

        $this->app->bind(RssEnricher::class, static fn (): RssEnricher => new RssEnricher(
            userAgent: config()->string('radar.user_agent'),
            timeout: config()->integer('radar.http_timeout'),
        ));

        $this->app->bind(Verifier::class, static fn (Application $app): Verifier => new Verifier(
            dns: $app->make(DnsResolver::class),
            userAgent: config()->string('radar.user_agent'),
            timeout: config()->integer('radar.http_timeout'),
            poolSize: config()->integer('radar.http_pool_size'),
        ));

        $this->app->bind(PrefixSource::class, static fn (Application $app): PrefixSource => new PrefixSource(
            prefixes: array_values(array_map(strval(...), config()->array('radar.prefixes'))),
            domains: $app->make(DomainName::class),
        ));

        $this->app->bind(CommonCrawlSource::class, static fn (): CommonCrawlSource => new CommonCrawlSource(
            indexCount: config()->integer('radar.commoncrawl.index_count'),
            userAgent: config()->string('radar.user_agent'),
            timeout: config()->integer('radar.http_timeout'),
        ));

        $this->app->bind(JobStreamSync::class, static fn (Application $app): JobStreamSync => new JobStreamSync(
            domains: $app->make(DomainName::class),
            baseUrl: config()->string('radar.jobstream.base_url'),
            apiKey: is_string(config('radar.jobstream.api_key')) ? config('radar.jobstream.api_key') : null,
            userAgent: config()->string('radar.user_agent'),
        ));

        $this->app->bind(JobSearch::class, static fn (): JobSearch => new JobSearch(
            newJobDays: config()->integer('radar.new_job_days'),
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
