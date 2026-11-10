{{-- Shared by the error pages: @include('errors.brand', ['code' => 404, 'icon' => 'compass']) --}}
@php($title = __("ui.errors.{$code}.title"))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="grid min-h-screen place-items-center bg-stone-50 px-4 font-sans text-stone-900 antialiased">
        <main class="w-full max-w-md py-16 text-center">
            <a href="{{ url('/') }}" aria-label="{{ __('ui.app_name') }}" class="inline-block"><x-logo /></a>
            <div class="mt-12 rounded-3xl border border-stone-200 bg-white p-8 shadow-sm">
                <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-accent-50 text-accent-700 ring-1 ring-accent-100">
                    <x-icon :name="$icon" class="size-7" />
                </span>
                <p class="mt-6 text-sm font-semibold tabular-nums text-accent-700">{{ $code }}</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight">{{ $title }}</h1>
                <p class="mt-2 text-sm text-stone-600">{{ __("ui.errors.{$code}.text") }}</p>
                <a href="{{ url('/') }}" class="mt-6 inline-flex items-center gap-2 rounded-lg bg-accent-700 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-800">
                    {{ __('ui.errors.home') }}
                    <x-icon name="arrow-right" />
                </a>
            </div>
        </main>
    </body>
</html>
