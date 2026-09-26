<?php

declare(strict_types=1);

namespace App\Discovery\Sources;

use App\Discovery\CandidateData;
use App\Discovery\Contracts\CandidateSource;
use App\Discovery\Fingerprint;
use App\Enums\CandidateSourceType;
use App\Enums\Strength;
use App\Models\PlatsbankenAd;
use Illuminate\Support\LazyCollection;

/**
 * Source B. Apply links in open Platsbanken ads that point at a Teamtailor
 * job (path /jobs/<digits>-...) or a *.teamtailor.com host become strong
 * candidates. The ads themselves are synced by JobStreamSync.
 */
final class JobStreamSource implements CandidateSource
{
    private const string JOB_PATH = '~^/jobs/\d+-~';

    public function type(): CandidateSourceType
    {
        return CandidateSourceType::JobStream;
    }

    public function collect(): LazyCollection
    {
        return PlatsbankenAd::query()
            ->whereNull('removed_at')
            ->whereNotNull('apply_host')
            ->lazyById(1000)
            ->filter(static fn (PlatsbankenAd $ad): bool => self::isTeamtailorLink((string) $ad->apply_host, (string) $ad->apply_url))
            ->map(static fn (PlatsbankenAd $ad): CandidateData => new CandidateData(
                (string) $ad->apply_host,
                CandidateSourceType::JobStream,
                Strength::Strong,
                $ad->id,
            ))
            ->values();
    }

    public static function isTeamtailorLink(string $host, string $url): bool
    {
        if (Fingerprint::isSlugHost($host)) {
            return true;
        }

        $path = parse_url(str_contains($url, '://') ? $url : "https://{$url}", PHP_URL_PATH);

        return is_string($path) && preg_match(self::JOB_PATH, $path) === 1;
    }
}
