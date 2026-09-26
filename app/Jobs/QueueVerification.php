<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Last link of the discovery chain: queues verification of the candidates
 * the chain collected.
 */
final class QueueVerification implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Artisan::call('radar:verify', ['--no-wait' => true]);
    }
}
