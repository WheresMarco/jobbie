<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| The radar's schedule (Europe/Stockholm). Scrapes run daily and also
| cover source A. Discovery runs weekly, in-process so that verification
| starts only after all candidates are in.
*/

Schedule::command('radar:scrape --no-wait')
    ->dailyAt('03:00')
    ->timezone('Europe/Stockholm')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('radar:discover --sync')
    ->weeklyOn(1, '01:00')
    ->timezone('Europe/Stockholm')
    ->withoutOverlapping(360)
    ->onOneServer()
    ->onSuccess(fn () => Illuminate\Support\Facades\Artisan::call('radar:verify', ['--no-wait' => true]));

// Phase 9: after the nightly scrape; JobStream data comes from discovery.
Schedule::command('radar:match-platsbanken')
    ->dailyAt('05:30')
    ->timezone('Europe/Stockholm')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('radar:update-psl')
    ->weeklyOn(0, '04:00')
    ->timezone('Europe/Stockholm')
    ->onOneServer();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
