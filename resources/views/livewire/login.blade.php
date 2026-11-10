@php($input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm shadow-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30')
<div>
    <h1 class="text-3xl font-extrabold tracking-tight">{{ __('ui.auth.welcome_back') }}</h1>
    <p class="mt-2 text-sm text-stone-600">{{ __('ui.auth.login_intro') }}</p>

    <form wire:submit="login" class="mt-8 space-y-5">
        <label class="block">
            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.auth.email') }}</span>
            <input type="email" wire:model="email" autocomplete="username" required autofocus class="{{ $input }}">
            @error('email')
                <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>
            @enderror
        </label>

        <label class="block">
            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.auth.password') }}</span>
            <input type="password" wire:model="password" autocomplete="current-password" required class="{{ $input }}">
            @error('password')
                <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>
            @enderror
        </label>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="remember" class="rounded border-stone-300 text-accent-600">
            {{ __('ui.auth.remember') }}
        </label>

        <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-lg bg-accent-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-accent-800">
            <span wire:loading.remove wire:target="login">{{ __('ui.auth.submit') }}</span>
            <span wire:loading wire:target="login">{{ __('ui.jobs.loading') }}</span>
            <x-icon name="arrow-right" wire:loading.remove wire:target="login" />
        </button>
    </form>

    @if (\App\Livewire\Register::enabled())
        <p class="mt-8 border-t border-stone-200 pt-6 text-center text-sm text-stone-600">
            {{ __('ui.auth.no_account') }}
            <a href="{{ route('register') }}" wire:navigate class="font-semibold text-accent-700 hover:underline">{{ __('ui.auth.register_link') }}</a>
        </p>
    @endif
</div>
