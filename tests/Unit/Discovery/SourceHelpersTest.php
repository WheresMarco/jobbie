<?php

declare(strict_types=1);

use App\Discovery\SourceRegistry;
use App\Discovery\Sources\CommonCrawlSource;
use App\Discovery\Sources\JobStreamSource;
use App\Enums\CandidateSourceType;

it('recognises Teamtailor apply links', function (string $host, string $url, bool $expected): void {
    expect(JobStreamSource::isTeamtailorLink($host, $url))->toBe($expected);
})->with([
    'custom domain job path' => ['career.kry.se', 'https://career.kry.se/jobs/4711001-sjukskoterska', true],
    'job path without scheme' => ['jobb.example.se', 'jobb.example.se/jobs/123-lagerarbetare?ref=af', true],
    'slug host' => ['kry.teamtailor.com', 'https://kry.teamtailor.com/', true],
    'other ATS' => ['example.varbi.com', 'https://example.varbi.com/what:job/jobID:123/', false],
    'jobs path without id' => ['www.example.se', 'https://www.example.se/jobs/lediga-jobb', false],
]);

it('keeps only career site slugs from Common Crawl URLs', function (string $url, ?string $expected): void {
    expect(CommonCrawlSource::slugHost($url))->toBe($expected);
})->with([
    ['https://kry.teamtailor.com/jobs/123-x', 'kry.teamtailor.com'],
    ['http://Acme-Corp.teamtailor.com:80/', 'acme-corp.teamtailor.com'],
    ['acme.teamtailor.com/jobs', 'acme.teamtailor.com'],
    ['https://www.teamtailor.com/en/', null],
    ['https://app.teamtailor.com/', null],
    ['https://a.b.teamtailor.com/', null],
    ['https://teamtailor.com/', null],
    ['https://kry.se/', null],
]);

it('maps source names from config and the command line', function (): void {
    expect(SourceRegistry::typeFromName('prefixes'))->toBe(CandidateSourceType::Prefix)
        ->and(SourceRegistry::typeFromName(' JobStream '))->toBe(CandidateSourceType::JobStream)
        ->and(SourceRegistry::typeFromName('redirects'))->toBe(CandidateSourceType::Redirect)
        ->and(SourceRegistry::typeFromName('altavista'))->toBeNull()
        ->and(SourceRegistry::isCollectable(CandidateSourceType::Redirect))->toBeFalse();
});
