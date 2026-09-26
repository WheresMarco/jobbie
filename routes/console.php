<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| The radar's schedule (Europe/Stockholm). Heavy work only goes on the
| queue, so the scheduler never blocks: scrapes run daily (and cover
| source A), discovery runs weekly as one queued chain that ends by
| queueing verification.
*/

Schedule::command('radar:scrape --no-wait')
    ->dailyAt('03:00')
    ->timezone('Europe/Stockholm')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('radar:discover --verify')
    ->weeklyOn(1, '01:00')
    ->timezone('Europe/Stockholm')
    ->withoutOverlapping()
    ->onOneServer();

// Phase 9: after the nightly scrape; JobStream data comes from discovery.
Schedule::command('radar:match-platsbanken')
    ->dailyAt('05:30')
    ->timezone('Europe/Stockholm')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('radar:update-psl')
    ->weeklyOn(0, '04:00')
    ->timezone('Europe/Stockholm')
    ->onOneServer()
    ->runInBackground();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
