<?php

declare(strict_types=1);

use App\Livewire\JobBrowser;
use App\Livewire\StatsPage;
use Illuminate\Support\Facades\Route;

Route::get('/', JobBrowser::class)->name('jobs');
Route::get('/stats', StatsPage::class)->name('stats');
