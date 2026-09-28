<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Create an account and log in. Can be turned off with RADAR_REGISTRATION.
 */
final class Register extends Component
{
    public const int MIN_PASSWORD_LENGTH = 8;

    /** Accounts per IP address and hour. */
    public const int MAX_PER_HOUR = 5;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        abort_unless(self::enabled(), 404);
    }

    public static function enabled(): bool
    {
        return config()->boolean('radar.registration');
    }

    public function register(): void
    {
        abort_unless(self::enabled(), 404);

        $this->email = mb_strtolower(trim($this->email));
        $this->name = trim($this->name);

        $this->validate(
            [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'password' => 'required|string|min:'.self::MIN_PASSWORD_LENGTH.'|confirmed',
            ],
            [
                'name.required' => __('ui.auth.name_required'),
                'name.max' => __('ui.auth.name_too_long'),
                'email.required' => __('ui.auth.email_required'),
                'email.email' => __('ui.auth.email_invalid'),
                'email.max' => __('ui.auth.email_invalid'),
                'email.unique' => __('ui.auth.email_taken'),
                'password.required' => __('ui.auth.password_required'),
                'password.min' => __('ui.auth.password_too_short', ['min' => self::MIN_PASSWORD_LENGTH]),
                'password.confirmed' => __('ui.auth.password_mismatch'),
            ],
        );

        $key = 'register|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            throw ValidationException::withMessages([
                'email' => __('ui.auth.throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }
        RateLimiter::hit($key, 3600);

        $user = User::query()->create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ]);

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('jobs');
    }

    public function render(): View
    {
        return view('livewire.register')->title(__('ui.auth.register_title'));
    }
}
