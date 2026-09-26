<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\Translates;
use App\Discovery\PlatsbankenMatcher;
use Illuminate\Console\Command;

final class RadarMatchPlatsbanken extends Command
{
    use Translates;

    protected $signature = 'radar:match-platsbanken';

    protected $description = 'Mark job postings that also appear in Platsbanken';

    public function handle(PlatsbankenMatcher $matcher): int
    {
        $result = $matcher->match();
        $matched = $result['apply_url'] + $result['org_title'];

        $this->components->info($this->t('radar.match_platsbanken.done', [
            'matched' => $matched,
            'active' => $result['active'],
            'share' => $result['active'] === 0 ? '0' : number_format(100 * $matched / $result['active'], 1, ',', ' '),
            'apply_url' => $result['apply_url'],
            'org_title' => $result['org_title'],
        ]));

        return self::SUCCESS;
    }
}
