<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Discovery\Contracts\CandidateSource;
use App\Discovery\Sources\CommonCrawlSource;
use App\Discovery\Sources\HttpArchiveSource;
use App\Discovery\Sources\JobStreamSource;
use App\Discovery\Sources\PrefixSource;
use App\Discovery\Sources\SearchApiSource;
use App\Discovery\Sources\TxtRecordSource;
use App\Enums\CandidateSourceType;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Maps source names used in config and on the command line to sources.
 */
final readonly class SourceRegistry
{
    /** Names accepted for each source type. */
    private const array NAMES = [
        'redirect' => CandidateSourceType::Redirect,
        'redirects' => CandidateSourceType::Redirect,
        'jobstream' => CandidateSourceType::JobStream,
        'platsbanken' => CandidateSourceType::JobStream,
        'prefix' => CandidateSourceType::Prefix,
        'prefixes' => CandidateSourceType::Prefix,
        'commoncrawl' => CandidateSourceType::CommonCrawl,
        'txt' => CandidateSourceType::Txt,
        'httparchive' => CandidateSourceType::HttpArchive,
        'http_archive' => CandidateSourceType::HttpArchive,
        'search' => CandidateSourceType::SearchApi,
        'search_api' => CandidateSourceType::SearchApi,
    ];

    /**
     * Sources that run through CollectCandidates.
     *
     * @var array<string, class-string<CandidateSource>>
     */
    private const array COLLECTABLE = [
        CandidateSourceType::JobStream->value => JobStreamSource::class,
        CandidateSourceType::Prefix->value => PrefixSource::class,
        CandidateSourceType::CommonCrawl->value => CommonCrawlSource::class,
        CandidateSourceType::Txt->value => TxtRecordSource::class,
        CandidateSourceType::HttpArchive->value => HttpArchiveSource::class,
        CandidateSourceType::SearchApi->value => SearchApiSource::class,
    ];

    public function __construct(private Container $container)
    {
    }

    public static function typeFromName(string $name): ?CandidateSourceType
    {
        return self::NAMES[strtolower(trim($name))] ?? null;
    }

    public static function isCollectable(CandidateSourceType $type): bool
    {
        return isset(self::COLLECTABLE[$type->value]);
    }

    public function source(CandidateSourceType $type): CandidateSource
    {
        $class = self::COLLECTABLE[$type->value] ?? throw new InvalidArgumentException("Source {$type->value} cannot be collected");

        return $this->container->make($class);
    }
}
