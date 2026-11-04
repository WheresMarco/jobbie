<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Responses in the shapes Varbi, SmartRecruiters and Workday serve
 * (checked against the live services on 3 November 2026), with made up
 * content.
 */
final class PlatformFeeds
{
    /**
     * Varbi's /what:rssfeed/.
     *
     * @param  array<int, string>  $jobs  job id => title
     */
    public static function varbiRss(string $account, string $employer, array $jobs): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0"><channel><title>Nya lediga jobb hos '.htmlspecialchars($employer, ENT_XML1).'</title>'
            ."<description>Nya lediga jobb hos {$employer}.</description><language>sv-se</language>";

        foreach ($jobs as $id => $title) {
            $link = "https://{$account}.varbi.com/en/what:job/jobID:{$id}/";
            $xml .= '<item><title>'.htmlspecialchars($title, ENT_XML1).'</title>'
                ."<link>{$link}</link>"
                .'<description>'.htmlspecialchars("{$title}\n\nVi söker dig som vill arbeta hos oss.", ENT_XML1).'</description>'
                .'<pubDate>Tue, 03 Nov 2026 00:00:00 +0100</pubDate>'
                ."<guid>{$link}</guid></item>";
        }

        return $xml.'</channel></rss>';
    }

    /**
     * One entry in SmartRecruiters' postings list.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function smartRecruitersItem(string $id, string $company, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'name' => "Ingenjör {$id}",
            'refNumber' => "REF{$id}",
            'company' => ['identifier' => $company, 'name' => "{$company} AB"],
            'releasedDate' => '2026-11-02T08:21:12.712Z',
            'location' => [
                'city' => 'Stockholm',
                'region' => 'Stockholm County',
                'country' => 'se',
                'remote' => false,
                'hybrid' => true,
                'fullLocation' => 'Stockholm, Stockholm County, Sweden',
            ],
            'typeOfEmployment' => ['id' => 'permanent', 'label' => 'Full-time'],
        ], $overrides);
    }

    /**
     * A SmartRecruiters posting detail: the list entry plus the ad text.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function smartRecruitersDetail(array $item): array
    {
        return [
            ...$item,
            'postingUrl' => "https://jobs.smartrecruiters.com/{$item['company']['identifier']}/{$item['id']}-ingenjor",
            'department' => ['id' => 1, 'label' => 'Engineering'],
            'jobAd' => ['sections' => [
                'companyDescription' => ['title' => 'Om oss', 'text' => '<p>Ett bolag.</p>'],
                'jobDescription' => ['title' => 'Jobbet', 'text' => '<p>Du bygger saker.</p>'],
                'qualifications' => ['title' => 'Krav', 'text' => '<ul><li>Erfarenhet</li></ul>'],
                'additionalInformation' => ['title' => 'Övrigt', 'text' => ''],
            ]],
        ];
    }

    /**
     * Workday's /jobs list. Country facet values are id => name.
     *
     * @param  list<array{path: string, title: string, req: string}>  $postings
     * @param  array<string, string>|null  $countries  Null leaves the facet out.
     * @return array<string, mixed>
     */
    public static function workdayList(array $postings, int $total, ?array $countries = ['se-id' => 'Sweden', 'no-id' => 'Norway']): array
    {
        $facets = [['facetParameter' => 'timeType', 'descriptor' => 'Time Type', 'values' => []]];
        if ($countries !== null) {
            $values = [];
            foreach ($countries as $id => $name) {
                $values[] = ['descriptor' => $name, 'id' => $id, 'count' => 1];
            }
            // Workday nests the country facet in a location group.
            $facets[] = ['facetParameter' => 'locationMainGroup', 'values' => [
                ['facetParameter' => 'locationCountry', 'descriptor' => 'Location Country', 'values' => $values],
            ]];
        }

        return [
            'total' => $total,
            'jobPostings' => array_map(static fn (array $p): array => [
                'title' => $p['title'],
                'externalPath' => $p['path'],
                'locationsText' => 'Västerås, Sweden',
                'postedOn' => 'Posted Yesterday',
                'bulletFields' => [$p['req']],
            ], $postings),
            'facets' => $facets,
        ];
    }

    /**
     * A Workday posting detail.
     *
     * @param  array<string, mixed>  $overrides  Merged into jobPostingInfo.
     * @return array<string, mixed>
     */
    public static function workdayDetail(string $host, string $site, string $path, string $req, array $overrides = []): array
    {
        return [
            'jobPostingInfo' => array_replace([
                'id' => md5($path),
                'title' => 'Processingenjör',
                'jobDescription' => '<p>Du utvecklar <b>processen</b>.</p>',
                'location' => 'Västerås, Västmanland County, Sweden',
                'additionalLocations' => ['Ludvika, Dalarna County, Sweden'],
                'postedOn' => 'Posted Yesterday',
                'startDate' => '2026-11-02',
                'timeType' => 'Full time',
                'jobReqId' => $req,
                'country' => ['descriptor' => 'Sweden', 'id' => 'se-id'],
                'remoteType' => 'Hybrid',
                'externalUrl' => "https://{$host}/{$site}{$path}",
            ], $overrides),
            'hiringOrganization' => ['name' => 'Exempel Sweden AB', 'url' => ''],
        ];
    }
}
