<div class="mx-auto mt-10 max-w-sm">
    <form wire:submit="login" class="space-y-4 rounded-xl border border-stone-200 bg-white p-6">
        <h1 class="text-lg font-semibold tracking-tight">{{ __('ui.auth.title') }}</h1>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('ui.auth.email') }}</span>
            <input type="email" wire:model="email" autocomplete="username" required autofocus
                   class="w-full rounded-md border border-stone-300 px-3 py-2 text-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30">
            @error('email')
                <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>
            @enderror
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('ui.auth.password') }}</span>
            <input type="password" wire:model="password" autocomplete="current-password" required
                   class="w-full rounded-md border border-stone-300 px-3 py-2 text-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30">
            @error('password')
                <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>
            @enderror
        </label>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="remember" class="rounded border-stone-300 text-accent-600">
            {{ __('ui.auth.remember') }}
        </label>

        <button type="submit" class="w-full rounded-lg bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-800">
            <span wire:loading.remove wire:target="login">{{ __('ui.auth.submit') }}</span>
            <span wire:loading wire:target="login">{{ __('ui.jobs.loading') }}</span>
        </button>
    </form>
</div>
