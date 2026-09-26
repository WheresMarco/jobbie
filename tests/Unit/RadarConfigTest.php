<?php

declare(strict_types=1);

it('exposes the radar settings with sensible defaults', function (): void {
    expect(config('radar.user_agent'))->toBeString()->not->toBeEmpty()
        ->and(config('radar.dns_driver'))->toBe('doh')
        ->and(config('radar.doh_endpoint'))->toStartWith('https://')
        ->and(config('radar.prefixes'))->toContain('career', 'jobb', 'karriar', 'xn--karrir-fua')
        ->and(config('radar.dead_recheck_days'))->toBe(30)
        ->and(config('radar.rejected_recheck_days'))->toBe(60)
        ->and(config('radar.new_job_days'))->toBe(7)
        ->and(config('radar.sources.enabled'))->toBe(['redirects', 'jobstream', 'prefixes', 'commoncrawl']);
});

it('keeps the Punycode prefix in sync with its Unicode form', function (): void {
    expect(idn_to_ascii('karriär'))->toBe('xn--karrir-fua');
});
