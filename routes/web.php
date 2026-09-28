<?php

declare(strict_types=1);

use App\Livewire\JobBrowser;
use App\Livewire\Login;
use App\Livewire\MyPostings;
use App\Livewire\Register;
use App\Livewire\StatsPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', JobBrowser::class)->name('jobs');
    Route::get('/mina', MyPostings::class)->name('mine');
    Route::get('/stats', StatsPage::class)->name('stats');

    Route::post('/logout', function (Request $request) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
