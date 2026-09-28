<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Sign in with email and password. Accounts are created with radar:user.
 */
final class Login extends Component
{
    public const int MAX_ATTEMPTS = 5;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate(
            ['email' => 'required|string|email', 'password' => 'required|string'],
            [
                'email.required' => __('ui.auth.email_required'),
                'email.email' => __('ui.auth.email_invalid'),
                'password.required' => __('ui.auth.password_required'),
            ],
        );

        $key = Str::transliterate(Str::lower($this->email).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => __('ui.auth.throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key);
            $this->reset('password');

            throw ValidationException::withMessages(['email' => __('ui.auth.failed')]);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        $this->redirectIntended(route('jobs'));
    }

    public function render(): View
    {
        return view('livewire.login')->title(__('ui.auth.title'));
    }
}
