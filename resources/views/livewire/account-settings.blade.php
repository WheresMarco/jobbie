@php($input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm shadow-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30')
<div class="space-y-8">
    <x-page-header icon="user" :title="__('ui.account.title')" :subtitle="__('ui.account.intro')" />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-6">
            {{-- Profile --}}
            <form wire:submit="saveProfile" class="rounded-2xl border border-stone-200 bg-white shadow-sm" aria-labelledby="profile-heading">
                <div class="p-6">
                    <h2 id="profile-heading" class="font-bold">{{ __('ui.account.profile') }}</h2>
                    <p class="mt-1 text-sm text-stone-600">{{ __('ui.account.profile_hint') }}</p>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.auth.name') }}</span>
                            <input type="text" wire:model="name" autocomplete="name" required class="{{ $input }}">
                            @error('name') <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.auth.email') }}</span>
                            <input type="email" wire:model="email" autocomplete="username" required class="{{ $input }}">
                            @error('email') <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-stone-100 bg-stone-50/60 px-6 py-3">
                    @if ($saved === 'profile')
                        <span class="flex items-center gap-1 text-sm text-accent-700" role="status" data-saved="profile"><x-icon name="check" />{{ __('ui.account.saved') }}</span>
                    @endif
                    <button type="submit" class="rounded-lg bg-accent-700 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-800">{{ __('ui.account.save') }}</button>
                </div>
            </form>

            {{-- Password --}}
            <form wire:submit="updatePassword" class="rounded-2xl border border-stone-200 bg-white shadow-sm" aria-labelledby="password-heading">
                <div class="p-6">
                    <h2 id="password-heading" class="font-bold">{{ __('ui.account.password') }}</h2>
                    <p class="mt-1 text-sm text-stone-600">{{ __('ui.auth.password_hint', ['min' => \App\Livewire\Register::MIN_PASSWORD_LENGTH]) }}</p>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label class="block sm:col-span-2 sm:max-w-[calc(50%-0.5rem)]">
                            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.account.current_password') }}</span>
                            <input type="password" wire:model="current_password" autocomplete="current-password" required class="{{ $input }}">
                            @error('current_password') <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.account.new_password') }}</span>
                            <input type="password" wire:model="password" autocomplete="new-password" required minlength="{{ \App\Livewire\Register::MIN_PASSWORD_LENGTH }}" class="{{ $input }}">
                            @error('password') <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium">{{ __('ui.auth.password_confirmation') }}</span>
                            <input type="password" wire:model="password_confirmation" autocomplete="new-password" required class="{{ $input }}">
                        </label>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-stone-100 bg-stone-50/60 px-6 py-3">
                    @if ($saved === 'password')
                        <span class="flex items-center gap-1 text-sm text-accent-700" role="status" data-saved="password"><x-icon name="check" />{{ __('ui.account.password_saved') }}</span>
                    @endif
                    <button type="submit" class="rounded-lg bg-accent-700 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-800">{{ __('ui.account.change_password') }}</button>
                </div>
            </form>

            {{-- Delete --}}
            <section x-data="{ open: @js($errors->has('delete_password')) }" class="rounded-2xl border border-red-200 bg-white p-6 shadow-sm" aria-labelledby="delete-heading">
                <h2 id="delete-heading" class="font-bold text-red-800">{{ __('ui.account.delete') }}</h2>
                <p class="mt-1 text-sm text-stone-600">{{ __('ui.account.delete_hint') }}</p>
                <button type="button" x-show="! open" @click="open = true; $nextTick(() => $refs.deletePassword.focus())"
                        class="mt-4 rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">{{ __('ui.account.delete') }}</button>
                <form x-show="open" x-cloak wire:submit="deleteAccount" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
                    <label class="block flex-1">
                        <span class="sr-only">{{ __('ui.auth.password') }}</span>
                        <input type="password" x-ref="deletePassword" wire:model="delete_password" autocomplete="current-password"
                               placeholder="{{ __('ui.account.delete_password') }}" class="{{ $input }}">
                        @error('delete_password') <span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span> @enderror
                    </label>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">{{ __('ui.account.delete_confirm') }}</button>
                        <button type="button" @click="open = false" class="rounded-lg px-3 py-2 text-sm text-stone-600 hover:bg-stone-100">{{ __('ui.match.add_cancel') }}</button>
                    </div>
                </form>
            </section>
        </div>

        {{-- Summary --}}
        <aside class="space-y-4 lg:sticky lg:top-20 lg:self-start">
            <div class="rounded-2xl border border-stone-200 bg-white p-6 text-center shadow-sm">
                {{-- Left alone between renders so a missing Gravatar is not fetched again; replaced when the name or email changes. --}}
                <x-avatar :user="$user" :size="72" class="mx-auto" wire:key="avatar-{{ md5($user->name.$user->email) }}" wire:ignore />
                <p class="mt-3 font-bold">{{ $user->name }}</p>
                <p class="truncate text-sm text-stone-500">{{ $user->email }}</p>
                @if ($user->is_admin)
                    <span class="mt-2 inline-block rounded-full bg-accent-50 px-2 py-0.5 text-xs font-semibold text-accent-800 ring-1 ring-accent-100">{{ __('ui.account.admin') }}</span>
                @endif
                <p class="mt-3 text-xs text-stone-500">
                    {{ __('ui.account.gravatar') }}
                    <a href="https://gravatar.com" target="_blank" rel="noopener" class="font-semibold text-accent-700 hover:underline">Gravatar</a>
                </p>
                @if ($user->created_at)
                    <p class="mt-1 text-xs text-stone-500">{{ __('ui.account.member_since', ['date' => $user->created_at->locale(app()->getLocale())->isoFormat('D MMMM YYYY')]) }}</p>
                @endif
            </div>
            <div class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="text-sm font-bold">{{ __('ui.account.your_data') }}</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-stone-600">{{ __('ui.account.data.marks') }}</dt><dd class="font-semibold tabular-nums" data-count="marks">{{ $marks }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-600">{{ __('ui.account.data.hidden') }}</dt><dd class="font-semibold tabular-nums" data-count="hidden">{{ $hiddenEmployers }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-600">{{ __('ui.account.data.cv') }}</dt><dd class="font-semibold">{{ $hasResume ? __('ui.account.data.yes') : __('ui.account.data.no') }}</dd></div>
                </dl>
                <p class="mt-4 flex gap-2 text-xs text-stone-500"><x-icon name="shield-check" class="size-3.5 text-accent-600" />{{ __('ui.account.private') }}</p>
            </div>
        </aside>
    </div>
</div>
