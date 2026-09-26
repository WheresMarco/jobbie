<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Sleep;

trait FollowsBatches
{
    /**
     * Show a progress bar until the batch has finished.
     */
    protected function follow(Batch $batch): void
    {
        $bar = $this->output->createProgressBar($batch->totalJobs);
        $bar->start();

        while (($current = Bus::findBatch($batch->id)) !== null && ! $current->finished() && ! $current->cancelled()) {
            $bar->setProgress($current->processedJobs() + $current->failedJobs);
            Sleep::for(2)->seconds();
        }

        $bar->setProgress($batch->totalJobs);
        $bar->finish();
        $this->newLine(2);
    }
}
