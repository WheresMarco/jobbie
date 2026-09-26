<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\Translates;
use App\Net\PublicSuffixList;
use Illuminate\Console\Command;
use Throwable;

final class RadarUpdatePsl extends Command
{
    use Translates;

    protected $signature = 'radar:update-psl';

    protected $description = 'Download the Public Suffix List to storage';

    public function handle(): int
    {
        try {
            $bytes = PublicSuffixList::update(config()->string('radar.user_agent'), config()->integer('radar.http_timeout'));
        } catch (Throwable $e) {
            $this->components->error($this->t('radar.psl.failed', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }

        $this->components->info($this->t('radar.psl.updated', ['bytes' => $bytes, 'path' => PublicSuffixList::cachedPath()]));

        return self::SUCCESS;
    }

}
