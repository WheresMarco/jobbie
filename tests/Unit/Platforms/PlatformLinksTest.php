<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Platforms\PlatformRegistry;

/*
| Link shapes are taken from apply links in Platsbanken ads (November 2026).
*/

it('recognises accounts from apply links', function (string $url, ?Platform $platform, ?string $key, ?string $host): void {
    $ref = app(PlatformRegistry::class)->recognize($url);

    expect($ref?->platform)->toBe($platform)
        ->and($ref?->externalKey)->toBe($key)
        ->and($ref?->host)->toBe($host);
})->with([
    'varbi apply' => ['https://regionstockholm.varbi.com/se/what:job/jobID:974874/type:job/where:125/apply:1', Platform::Varbi, 'regionstockholm', 'regionstockholm.varbi.com'],
    'varbi without scheme' => ['gotene.varbi.com/se/', Platform::Varbi, 'gotene', 'gotene.varbi.com'],
    'varbi own site' => ['https://www.varbi.com/sv/', null, null, null],
    'smartrecruiters job' => ['https://jobs.smartrecruiters.com/Vattenfall/744000150707548-senior-inkopare', Platform::SmartRecruiters, 'Vattenfall', 'jobs.smartrecruiters.com'],
    'smartrecruiters careers' => ['https://careers.smartrecruiters.com/HMGroup', Platform::SmartRecruiters, 'HMGroup', 'jobs.smartrecruiters.com'],
    'smartrecruiters one click' => ['https://jobs.smartrecruiters.com/oneclick-ui/company/x/publication/y', null, null, null],
    'workday job' => ['https://boliden.wd3.myworkdayjobs.com/BolidenJobs/job/Skelleftehamn/Tekniker_JOB_015714', Platform::Workday, 'boliden.wd3.myworkdayjobs.com/BolidenJobs', 'boliden.wd3.myworkdayjobs.com'],
    'workday with locale' => ['https://hitachi.wd1.myworkdayjobs.com/en-US/hitachi/job/Ludvika/Engineer_R0147957/apply', Platform::Workday, 'hitachi.wd1.myworkdayjobs.com/hitachi', 'hitachi.wd1.myworkdayjobs.com'],
    'workday api path' => ['https://hitachi.wd1.myworkdayjobs.com/wday/cxs/hitachi/hitachi/jobs', null, null, null],
    'teamtailor' => ['https://career.kry.se/jobs/4711-lakare', null, null, null],
    'other' => ['https://recruit.visma.com/spa/public/apply?guidAssignment=abc', null, null, null],
]);

it('gives every link to one job the same key', function (string $a, string $b): void {
    $registry = app(PlatformRegistry::class);

    expect($registry->jobKey($a))->not->toBeNull()->toBe($registry->jobKey($b));
})->with([
    'teamtailor slug and custom domain' => ['https://kry.teamtailor.com/jobs/4711-lakare?utm=af', 'https://career.kry.se/jobs/4711-lakare'],
    'varbi apply form and English ad' => ['https://axo.varbi.com/se/what:job/jobID:975028/type:job/where:125/apply:1', 'https://axo.varbi.com/en/what:job/jobID:975028/'],
    'smartrecruiters tracking' => ['https://jobs.smartrecruiters.com/AFRY/744000149304159-junior?oga=true', 'https://jobs.smartrecruiters.com/AFRY/744000149304159-junior-dataspecialist'],
    'workday apply step and locale' => ['https://hitachi.wd1.myworkdayjobs.com/en-US/hitachi/job/Ludvika/Engineer_R0147957/apply/autofillWithResume', 'https://hitachi.wd1.myworkdayjobs.com/hitachi/job/Ludvika/Engineer_R0147957'],
]);

it('has no job key for links that are not to a job', function (string $url): void {
    expect(app(PlatformRegistry::class)->jobKey($url))->toBeNull();
})->with([
    'varbi start page' => ['https://gotene.varbi.com/se/'],
    'smartrecruiters company' => ['https://jobs.smartrecruiters.com/Vattenfall'],
    'workday site' => ['https://boliden.wd3.myworkdayjobs.com/BolidenJobs'],
    'unknown' => ['https://www.example.se/jobb/123'],
]);

it('only recognises accounts on enabled platforms', function (): void {
    config(['radar.platforms.enabled' => ['workday']]);
    $registry = app(PlatformRegistry::class);

    expect($registry->enabled())->toBe([Platform::Workday])
        ->and($registry->recognize('https://gotene.varbi.com/se/'))->toBeNull()
        // Job keys still work for every platform, for boards that already exist.
        ->and($registry->jobKey('https://axo.varbi.com/en/what:job/jobID:975028/'))->toBe('varbi:975028');
});

it('parses platform names and ignores unknown or unscraped ones', function (): void {
    expect(PlatformRegistry::parseNames(['Varbi', ' workday ', 'platsbanken', 'monster', 'varbi']))
        ->toBe([Platform::Varbi, Platform::Workday]);
});
