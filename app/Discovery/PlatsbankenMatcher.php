<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Discovery\JobStream\JobStreamSync;
use App\Models\Board;
use App\Models\BoardAlias;
use App\Models\Domain;
use App\Models\JobPosting;
use App\Models\PlatsbankenAd;
use App\Net\DomainName;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9: marks job postings that also appear in Platsbanken, to test
 * the premise that these ads are not visible elsewhere.
 *
 * 1. Apply link: the Platsbanken ad links to the same Teamtailor job, by
 *    job id in the path (/jobs/<id>-...), whichever host it uses, or else
 *    by identical host and path.
 * 2. Organisation number and title: the board's organisation number (from
 *    ads matched in step 1, or from the domains table) and the same
 *    normalised title.
 */
final readonly class PlatsbankenMatcher
{
    public const string BY_APPLY_URL = 'apply_url';

    public const string BY_ORG_TITLE = 'org_title';

    private const string JOB_ID = '~/jobs/(\d+)(?:-|/|$)~';

    public function __construct(private DomainName $domains)
    {
    }

    /**
     * @return array{active: int, apply_url: int, org_title: int}
     */
    public function match(): array
    {
        [$byJobId, $byUrl, $byOrgTitle, $orgOfAd] = $this->indexAds();

        $matches = [];
        $boardOrgVotes = [];
        $unmatched = [];
        $active = 0;

        foreach (JobPosting::query()->where('active', true)->select('id', 'board_id', 'url', 'title')->lazyById(2000) as $posting) {
            $active++;
            $adId = null;
            if (preg_match(self::JOB_ID, (string) parse_url($posting->url, PHP_URL_PATH), $m) === 1) {
                $adId = $byJobId[$m[1]] ?? null;
            }
            $adId ??= $byUrl[self::urlKey($posting->url)] ?? null;

            if ($adId !== null) {
                $matches[$posting->id] = [$adId, self::BY_APPLY_URL];
                if (isset($orgOfAd[$adId])) {
                    $boardOrgVotes[$posting->board_id][$orgOfAd[$adId]] = ($boardOrgVotes[$posting->board_id][$orgOfAd[$adId]] ?? 0) + 1;
                }
            } else {
                $unmatched[] = [$posting->id, $posting->board_id, $posting->title];
            }
        }

        $boardOrg = $this->boardOrganisations($boardOrgVotes);
        foreach ($unmatched as [$id, $boardId, $title]) {
            $org = $boardOrg[$boardId] ?? null;
            $key = $org === null ? null : $org.'|'.JobStreamSync::normalizeTitle($title);
            if ($key !== null && isset($byOrgTitle[$key])) {
                $matches[$id] = [$byOrgTitle[$key], self::BY_ORG_TITLE];
            }
        }

        $this->store($matches);

        $counts = array_count_values(array_column($matches, 1));

        return [
            'active' => $active,
            'apply_url' => $counts[self::BY_APPLY_URL] ?? 0,
            'org_title' => $counts[self::BY_ORG_TITLE] ?? 0,
        ];
    }

    /**
     * Open Platsbanken ads indexed three ways.
     *
     * @return array{array<string, string>, array<string, string>, array<string, string>, array<string, string>}
     */
    private function indexAds(): array
    {
        $byJobId = $byUrl = $byOrgTitle = $orgOfAd = [];

        foreach (PlatsbankenAd::query()->whereNull('removed_at')->select('id', 'apply_url', 'org_nr', 'title_normalized')->lazyById(2000) as $ad) {
            if ($ad->apply_url !== null) {
                $url = str_contains($ad->apply_url, '://') ? $ad->apply_url : "https://{$ad->apply_url}";
                if (preg_match(self::JOB_ID, (string) parse_url($url, PHP_URL_PATH), $m) === 1) {
                    $byJobId[$m[1]] = $ad->id;
                }
                $byUrl[self::urlKey($url)] = $ad->id;
            }

            if ($ad->org_nr !== null) {
                $orgOfAd[$ad->id] = $ad->org_nr;
                if ($ad->title_normalized !== null) {
                    $byOrgTitle[$ad->org_nr.'|'.$ad->title_normalized] = $ad->id;
                }
            }
        }

        return [$byJobId, $byUrl, $byOrgTitle, $orgOfAd];
    }

    /**
     * The organisation number per board: the most common one among its
     * postings matched by apply link, else the one recorded for the
     * registrable domain of the board's host or an alias.
     *
     * @param  array<int, array<string, int>>  $votes
     * @return array<int, string>
     */
    private function boardOrganisations(array $votes): array
    {
        $orgs = [];
        foreach ($votes as $boardId => $counts) {
            arsort($counts);
            $orgs[$boardId] = (string) array_key_first($counts);
        }

        $domainOrgs = Domain::query()->whereNotNull('org_nr')->pluck('org_nr', 'base_domain');
        if ($domainOrgs->isEmpty()) {
            return $orgs;
        }

        $hosts = Board::query()->pluck('canonical_host', 'id')->map(static fn ($host) => [(string) $host])->all();
        foreach (BoardAlias::query()->select('host', 'board_id')->get() as $alias) {
            $hosts[$alias->board_id][] = $alias->host;
        }

        foreach ($hosts as $boardId => $boardHosts) {
            if (isset($orgs[$boardId])) {
                continue;
            }
            foreach ($boardHosts as $host) {
                $base = $this->domains->registrable($host);
                if ($base !== null && isset($domainOrgs[$base])) {
                    $orgs[$boardId] = (string) $domainOrgs[$base];

                    break;
                }
            }
        }

        return $orgs;
    }

    /**
     * @param  array<int, array{string, string}>  $matches  posting id => [ad id, method]
     */
    private function store(array $matches): void
    {
        DB::transaction(function () use ($matches): void {
            JobPosting::query()->where('in_platsbanken', true)->update([
                'in_platsbanken' => false,
                'platsbanken_ad_id' => null,
                'platsbanken_match' => null,
            ]);

            foreach ($matches as $postingId => [$adId, $method]) {
                JobPosting::query()->whereKey($postingId)->update([
                    'in_platsbanken' => true,
                    'platsbanken_ad_id' => $adId,
                    'platsbanken_match' => $method,
                ]);
            }
        });
    }

    /**
     * Lower case host and path without query, fragment or trailing slash.
     */
    private static function urlKey(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = rtrim(strtolower((string) parse_url($url, PHP_URL_PATH)), '/');

        return $host.$path;
    }
}
