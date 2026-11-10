<?php

declare(strict_types=1);

use App\Http\Middleware\ShowLandingToGuests;
use App\Livewire\AccountSettings;
use App\Livewire\JobBrowser;
use App\Livewire\Login;
use App\Livewire\MyPostings;
use App\Livewire\Register;
use App\Livewire\ResumeMatches;
use App\Livewire\StatsPage;
use App\Livewire\Welcome;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

// Guests see the landing page here, so this one sits outside the auth group.
Route::get('/', JobBrowser::class)->middleware([ShowLandingToGuests::class, 'auth'])->name('jobs');

Route::middleware('auth')->group(function (): void {
    Route::get('/mina', MyPostings::class)->name('mine');
    Route::get('/matcha', ResumeMatches::class)->name('match');
    Route::get('/valkommen', Welcome::class)->name('welcome');
    Route::get('/konto', AccountSettings::class)->name('account');
    Route::get('/stats', StatsPage::class)->middleware('can:admin')->name('stats');

    Route::post('/logout', function (Request $request) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
