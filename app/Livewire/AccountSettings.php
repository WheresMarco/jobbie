<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The account settings on /konto: name and email, password, and deleting
 * the account with everything saved for it.
 */
final class AccountSettings extends Component
{
    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $delete_password = '';

    /** The form that was just saved, to confirm it: "profile" or "password". */
    public ?string $saved = null;

    public function mount(): void
    {
        $user = $this->user();
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function saveProfile(): void
    {
        $user = $this->user();
        $this->email = mb_strtolower(trim($this->email));
        $this->name = trim($this->name);

        $this->validate(
            [
                'name' => 'required|string|max:255',
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            ],
            [
                'name.required' => __('ui.auth.name_required'),
                'name.max' => __('ui.auth.name_too_long'),
                'email.required' => __('ui.auth.email_required'),
                'email.email' => __('ui.auth.email_invalid'),
                'email.max' => __('ui.auth.email_invalid'),
                'email.unique' => __('ui.auth.email_taken'),
            ],
        );

        $user->update(['name' => $this->name, 'email' => $this->email]);
        $this->saved = 'profile';
    }

    public function updatePassword(): void
    {
        $this->validate(
            [
                'current_password' => 'required|string|current_password:web',
                'password' => 'required|string|min:'.Register::MIN_PASSWORD_LENGTH.'|confirmed',
            ],
            [
                'current_password.required' => __('ui.auth.password_required'),
                'current_password.current_password' => __('ui.account.wrong_password'),
                'password.required' => __('ui.auth.password_required'),
                'password.min' => __('ui.auth.password_too_short', ['min' => Register::MIN_PASSWORD_LENGTH]),
                'password.confirmed' => __('ui.auth.password_mismatch'),
            ],
        );

        $this->user()->update(['password' => $this->password]);
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->saved = 'password';
    }

    /**
     * Deletes the user. Marks, hidden employers and the CV profile go with it
     * through the foreign keys.
     */
    public function deleteAccount(): void
    {
        $this->validate(
            ['delete_password' => 'required|string|current_password:web'],
            [
                'delete_password.required' => __('ui.auth.password_required'),
                'delete_password.current_password' => __('ui.account.wrong_password'),
            ],
        );

        $user = $this->user();
        Auth::guard('web')->logout();
        $user->delete();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect('/');
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.account-settings', [
            'user' => $user,
            'marks' => $user->marks()->count(),
            'hiddenEmployers' => $user->hiddenEmployers()->count(),
            'hasResume' => $user->resume()->exists(),
        ])->title(__('ui.account.title'));
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
