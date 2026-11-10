@php
    $navLink = fn (bool $active): string => $active
        ? 'flex items-center gap-2 rounded-lg bg-accent-50 px-3 py-1.5 font-semibold text-accent-800'
        : 'flex items-center gap-2 rounded-lg px-3 py-1.5 text-stone-600 hover:bg-stone-100 hover:text-stone-900';
    $menuLink = fn (bool $active): string => $active
        ? 'flex items-center gap-2 rounded-lg bg-stone-100 px-3 py-2 font-medium text-stone-900'
        : 'flex items-center gap-2 rounded-lg px-3 py-2 text-stone-700 hover:bg-stone-100';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-screen flex-col bg-stone-50 font-sans text-stone-900 antialiased">
        <header class="sticky top-0 z-30 border-b border-stone-200/80 bg-white/85 backdrop-blur">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-2.5 sm:px-6">
                <a href="{{ route('jobs') }}" wire:navigate aria-label="{{ __('ui.app_name') }}">
                    <x-logo />
                </a>
                @auth
                    <nav class="order-last -mx-1 flex w-full items-center gap-1 overflow-x-auto whitespace-nowrap text-sm sm:order-2 sm:mx-0 sm:ml-auto sm:w-auto">
                        <a href="{{ route('jobs', ['unseen' => 1]) }}" wire:navigate class="{{ $navLink(request()->routeIs('jobs')) }}">
                            <x-icon name="briefcase" />
                            {{ __('ui.nav.jobs') }}
                            <livewire:unseen-counter />
                        </a>
                        <a href="{{ route('mine') }}" wire:navigate class="{{ $navLink(request()->routeIs('mine')) }}">
                            <x-icon name="bookmark" />
                            {{ __('ui.nav.mine') }}
                        </a>
                        <a href="{{ route('match') }}" wire:navigate class="{{ $navLink(request()->routeIs('match')) }}">
                            <x-icon name="sparkles" />
                            {{ __('ui.nav.match') }}
                        </a>
                    </nav>
                    <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative sm:order-3">
                        <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="true"
                                class="flex items-center gap-1 rounded-full p-0.5 pr-1.5 text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                                aria-label="{{ __('ui.nav.account') }}">
                            <x-avatar :user="auth()->user()" />
                            <x-icon name="chevron-down" class="size-3.5" />
                        </button>
                        <div x-show="open" x-cloak x-transition.origin.top.right
                             class="absolute right-0 z-40 mt-2 w-64 rounded-xl border border-stone-200 bg-white p-1.5 text-sm shadow-lg shadow-stone-900/5">
                            <div class="border-b border-stone-100 px-3 pb-2.5 pt-2">
                                <p class="flex items-center gap-2 font-semibold">
                                    <span class="truncate">{{ auth()->user()->name }}</span>
                                    @can('admin')
                                        <span class="shrink-0 rounded-full bg-accent-50 px-1.5 py-px text-[0.625rem] font-semibold uppercase tracking-wide text-accent-800 ring-1 ring-accent-100">{{ __('ui.account.admin') }}</span>
                                    @endcan
                                </p>
                                <p class="truncate text-xs text-stone-500">{{ auth()->user()->email }}</p>
                            </div>
                            <div class="space-y-0.5 py-1">
                                <a href="{{ route('account') }}" wire:navigate class="{{ $menuLink(request()->routeIs('account')) }}">
                                    <x-icon name="settings" class="text-stone-400" />
                                    {{ __('ui.nav.settings') }}
                                </a>
                                @can('admin')
                                    <a href="{{ route('stats') }}" wire:navigate class="{{ $menuLink(request()->routeIs('stats')) }}">
                                        <x-icon name="chart" class="text-stone-400" />
                                        {{ __('ui.nav.stats') }}
                                        <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-amber-50 px-1.5 py-px text-[0.625rem] font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200" title="{{ __('ui.account.admin_only') }}" data-admin-badge>
                                            <x-icon name="shield-check" class="size-3" />{{ __('ui.account.admin') }}
                                        </span>
                                    </a>
                                @endcan
                                <a href="{{ route('welcome') }}" wire:navigate class="{{ $menuLink(request()->routeIs('welcome')) }}">
                                    <x-icon name="compass" class="text-stone-400" />
                                    {{ __('ui.nav.welcome') }}
                                </a>
                            </div>
                            <form method="POST" action="{{ route('logout') }}" class="border-t border-stone-100 pt-1">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-stone-700 hover:bg-stone-100">
                                    <x-icon name="log-out" class="text-stone-400" />
                                    {{ __('ui.nav.logout') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endauth
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 sm:py-8">
            {{ $slot }}
        </main>

        @include('partials.footer')
    </body>
</html>
