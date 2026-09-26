<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ isset($title) ? $title.' · ' : '' }}{{ __('ui.app_name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased">
        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <a href="{{ route('jobs') }}" class="flex items-center gap-2 font-semibold tracking-tight" wire:navigate>
                    <span class="inline-block size-2.5 rounded-full bg-accent-600" aria-hidden="true"></span>
                    {{ __('ui.app_name') }}
                </a>
                <nav class="flex gap-1 text-sm">
                    <a href="{{ route('jobs') }}" wire:navigate
                       @class(['rounded-md px-3 py-1.5 hover:bg-stone-100', 'bg-stone-100 font-medium' => request()->routeIs('jobs')])>{{ __('ui.nav.jobs') }}</a>
                    <a href="{{ route('stats') }}" wire:navigate
                       @class(['rounded-md px-3 py-1.5 hover:bg-stone-100', 'bg-stone-100 font-medium' => request()->routeIs('stats')])>{{ __('ui.nav.stats') }}</a>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
            {{ $slot }}
        </main>
    </body>
</html>
