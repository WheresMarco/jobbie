@php($input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm shadow-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30')
<div>
    <h1 class="text-3xl font-extrabold tracking-tight">{{ __('ui.auth.register_title') }}</h1>
    <p class="mt-2 text-sm text-stone-600">{{ __('ui.auth.register_intro') }}</p>

    <form wire:submit="register" class="mt-8 space-y-5">
        <label class="block">
            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.auth.name') }}</span>
            <input type="text" wire:model="name" autocomplete="name" required autofocus class="{{ $input }}">
            @error('name')
                <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>
            @enderror
        </label>

        <label class="block">
            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.auth.email') }}</span>
            <input type="email" wire:model="email" autocomplete="username" required class="{{ $input }}">
            @error('email')
                <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>
            @enderror
        </label>

        <label class="block">
            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.auth.password') }}</span>
            <input type="password" wire:model="password" autocomplete="new-password" required minlength="{{ \App\Livewire\Register::MIN_PASSWORD_LENGTH }}" class="{{ $input }}">
            <span class="mt-1 block text-xs text-stone-500">{{ __('ui.auth.password_hint', ['min' => \App\Livewire\Register::MIN_PASSWORD_LENGTH]) }}</span>
            @error('password')
                <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>
            @enderror
        </label>

        <label class="block">
            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.auth.password_confirmation') }}</span>
            <input type="password" wire:model="password_confirmation" autocomplete="new-password" required class="{{ $input }}">
        </label>

        <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-lg bg-accent-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-accent-800">
            <span wire:loading.remove wire:target="register">{{ __('ui.auth.register_submit') }}</span>
            <span wire:loading wire:target="register">{{ __('ui.jobs.loading') }}</span>
            <x-icon name="arrow-right" wire:loading.remove wire:target="register" />
        </button>
    </form>

    <p class="mt-8 border-t border-stone-200 pt-6 text-center text-sm text-stone-600">
        {{ __('ui.auth.have_account') }}
        <a href="{{ route('login') }}" wire:navigate class="font-semibold text-accent-700 hover:underline">{{ __('ui.auth.title') }}</a>
    </p>
</div>
