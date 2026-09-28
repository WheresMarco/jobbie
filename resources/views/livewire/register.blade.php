<div class="mx-auto mt-10 max-w-sm">
    <form wire:submit="register" class="space-y-4 rounded-xl border border-stone-200 bg-white p-6">
        <h1 class="text-lg font-semibold tracking-tight">{{ __('ui.auth.register_title') }}</h1>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('ui.auth.name') }}</span>
            <input type="text" wire:model="name" autocomplete="name" required autofocus
                   class="w-full rounded-md border border-stone-300 px-3 py-2 text-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30">
            @error('name')
                <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>
            @enderror
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('ui.auth.email') }}</span>
            <input type="email" wire:model="email" autocomplete="username" required
                   class="w-full rounded-md border border-stone-300 px-3 py-2 text-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30">
            @error('email')
                <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>
            @enderror
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('ui.auth.password') }}</span>
            <input type="password" wire:model="password" autocomplete="new-password" required minlength="{{ \App\Livewire\Register::MIN_PASSWORD_LENGTH }}"
                   class="w-full rounded-md border border-stone-300 px-3 py-2 text-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30">
            <span class="mt-1 block text-xs text-stone-500">{{ __('ui.auth.password_hint', ['min' => \App\Livewire\Register::MIN_PASSWORD_LENGTH]) }}</span>
            @error('password')
                <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>
            @enderror
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('ui.auth.password_confirmation') }}</span>
            <input type="password" wire:model="password_confirmation" autocomplete="new-password" required
                   class="w-full rounded-md border border-stone-300 px-3 py-2 text-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30">
        </label>

        <button type="submit" class="w-full rounded-lg bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-800">
            <span wire:loading.remove wire:target="register">{{ __('ui.auth.register_submit') }}</span>
            <span wire:loading wire:target="register">{{ __('ui.jobs.loading') }}</span>
        </button>

        <p class="text-center text-sm text-stone-600">
            {{ __('ui.auth.have_account') }}
            <a href="{{ route('login') }}" wire:navigate class="text-accent-700 hover:underline">{{ __('ui.auth.title') }}</a>
        </p>
    </form>
</div>
