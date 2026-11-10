@php
    /** @var \App\Stats\Coverage $coverage */
    /** @var list<array{key: string, name: string, logo: string|null}> $platforms */
    // Logos differ in how much of their box they fill, so each gets its own height.
    $logoHeights = [
        'teamtailor' => 'h-4',
        'platsbanken' => 'h-6',
        'smartrecruiters' => 'h-7',
        'workday' => 'h-8',
        'greenhouse' => 'h-6',
        'lever' => 'h-5',
        'ashby' => 'h-6',
    ];
    $features = [
        'search' => 'search',
        'filter' => 'sliders',
        'employers' => 'building',
        'unseen' => 'eye',
        'track' => 'bookmark',
        'hide' => 'eye-off',
    ];
    $faq = array_filter(
        ['sources', 'fresh', 'hidden', 'cv', 'private', 'delete'],
        static fn (string $key): bool => $key !== 'cv' || $matching,
    );
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @include('partials.head')
    </head>
    <body class="bg-stone-50 font-sans text-stone-900 antialiased">
        {{-- Header --}}
        <header class="sticky top-0 z-30 border-b border-stone-200/80 bg-white/85 backdrop-blur">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <a href="{{ url('/') }}" aria-label="{{ __('ui.app_name') }}"><x-logo /></a>
                <nav class="hidden items-center gap-1 text-sm md:flex" aria-label="{{ __('ui.landing.nav_label') }}">
                    <a href="#funktioner" class="rounded-lg px-3 py-1.5 text-stone-600 hover:bg-stone-100 hover:text-stone-900">{{ __('ui.landing.nav.features') }}</a>
                    @if ($matching)
                        <a href="#cv" class="rounded-lg px-3 py-1.5 text-stone-600 hover:bg-stone-100 hover:text-stone-900">{{ __('ui.landing.nav.match') }}</a>
                    @endif
                    <a href="#sa-funkar-det" class="rounded-lg px-3 py-1.5 text-stone-600 hover:bg-stone-100 hover:text-stone-900">{{ __('ui.landing.nav.how') }}</a>
                    <a href="#fragor" class="rounded-lg px-3 py-1.5 text-stone-600 hover:bg-stone-100 hover:text-stone-900">{{ __('ui.landing.nav.faq') }}</a>
                </nav>
                <div class="flex items-center gap-2 text-sm">
                    <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 font-semibold text-stone-700 hover:bg-stone-100">{{ __('ui.auth.title') }}</a>
                    @if ($registration)
                        <a href="{{ route('register') }}" class="hidden rounded-lg bg-accent-700 px-4 py-2 font-semibold text-white hover:bg-accent-800 sm:inline-block">{{ __('ui.landing.cta') }}</a>
                    @endif
                </div>
            </div>
        </header>

        <main>
            {{-- Hero --}}
            <section class="relative overflow-hidden bg-gradient-to-br from-accent-700 via-accent-800 to-accent-950 text-white">
                <svg class="pointer-events-none absolute -right-60 -top-60 size-[60rem] text-white/[0.07]" viewBox="0 0 200 200" fill="none" stroke="currentColor" aria-hidden="true">
                    <circle cx="100" cy="100" r="20"/><circle cx="100" cy="100" r="40"/><circle cx="100" cy="100" r="60"/><circle cx="100" cy="100" r="80"/><circle cx="100" cy="100" r="99"/>
                </svg>
                <div class="pointer-events-none absolute -bottom-40 -left-20 size-[30rem] rounded-full bg-accent-400/20 blur-3xl" aria-hidden="true"></div>

                <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                    <div>
                        <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-accent-100 ring-1 ring-white/15">
                            <x-icon name="radar" class="size-3.5" />{{ __('ui.brand.eyebrow') }}
                        </p>
                        <h1 class="mt-5 text-4xl font-extrabold leading-[1.05] tracking-tight sm:text-6xl">{{ __('ui.landing.headline') }}</h1>
                        <p class="mt-6 max-w-xl text-lg leading-relaxed text-accent-50/90">{{ __('ui.landing.pitch') }}</p>
                        <div class="mt-8 flex flex-wrap gap-3">
                            @if ($registration)
                                <a href="{{ route('register') }}" class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-semibold text-accent-800 shadow-sm hover:bg-accent-50">
                                    {{ __('ui.landing.cta') }}<x-icon name="arrow-right" />
                                </a>
                            @endif
                            <a href="{{ route('login') }}" @class([
                                'inline-flex items-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold',
                                'bg-white/10 text-white ring-1 ring-white/25 hover:bg-white/20' => $registration,
                                'bg-white text-accent-800 shadow-sm hover:bg-accent-50' => ! $registration,
                            ])>{{ __('ui.landing.login') }}</a>
                        </div>

                        @if ($coverage->postings > 0)
                            <dl class="mt-12 grid max-w-lg grid-cols-3 gap-6 border-t border-white/15 pt-8" data-numbers>
                                <div class="flex flex-col-reverse justify-end">
                                    <dt class="mt-1 text-sm text-accent-200">{{ __('ui.welcome.numbers.postings') }}</dt>
                                    <dd class="text-2xl font-extrabold tabular-nums sm:text-3xl">{{ number_format($coverage->postings, 0, ',', ' ') }}</dd>
                                </div>
                                <div class="flex flex-col-reverse justify-end">
                                    <dt class="mt-1 text-sm text-accent-200">{{ __('ui.welcome.numbers.employers') }}</dt>
                                    <dd class="text-2xl font-extrabold tabular-nums sm:text-3xl">{{ number_format($coverage->employers, 0, ',', ' ') }}</dd>
                                </div>
                                <div class="flex flex-col-reverse justify-end">
                                    <dt class="mt-1 text-sm text-accent-200">{{ __('ui.landing.numbers.sources') }}</dt>
                                    <dd class="text-2xl font-extrabold tabular-nums sm:text-3xl">{{ count($platforms) }}</dd>
                                </div>
                            </dl>
                        @endif
                    </div>

                    {{-- Product illustration: the job list, drawn in HTML. --}}
                    <div class="relative" aria-hidden="true">
                        <div class="absolute -inset-4 rounded-[2rem] bg-white/5 ring-1 ring-white/10"></div>
                        <div class="relative overflow-hidden rounded-2xl bg-white text-stone-900 shadow-2xl shadow-accent-950/40">
                            <div class="flex items-center gap-1.5 border-b border-stone-100 bg-stone-50 px-4 py-3">
                                <span class="size-2.5 rounded-full bg-stone-300"></span><span class="size-2.5 rounded-full bg-stone-300"></span><span class="size-2.5 rounded-full bg-stone-300"></span>
                            </div>
                            <div class="space-y-4 p-5 sm:pb-14">
                                <div class="flex items-center gap-2 rounded-xl border border-stone-200 px-3 py-2.5 text-sm text-stone-400">
                                    <x-icon name="search" />{{ __('ui.landing.mock.search') }}
                                </div>
                                <ul class="divide-y divide-stone-100 text-sm">
                                    @foreach (__('ui.landing.mock.rows') as $i => $row)
                                        <li class="py-3">
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="flex items-center gap-2 font-semibold">
                                                    @if ($i < 2)<span class="size-2 rounded-full bg-accent-600"></span>@endif
                                                    {{ $row['title'] }}
                                                </span>
                                                <span @class(['rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums', 'bg-accent-600 text-white' => $row['score'] >= 80, 'bg-accent-100 text-accent-700' => $row['score'] < 80])>{{ $row['score'] }}</span>
                                            </div>
                                            <div class="mt-1.5 flex items-center gap-2 text-xs text-stone-500">
                                                <span class="h-2 w-20 rounded-full bg-stone-200"></span>
                                                <span class="inline-flex items-center gap-1"><x-icon name="map-pin" class="size-3" />{{ $row['city'] }}</span>
                                                <span class="rounded-full bg-accent-100 px-2 py-0.5 font-medium text-accent-700">{{ $row['remote'] }}</span>
                                                @if ($i === 0)<span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800">{{ __('ui.jobs.new_badge') }}</span>@endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                        <div class="absolute -bottom-7 -left-6 hidden items-center gap-3 rounded-xl bg-white px-4 py-3 text-sm text-stone-900 shadow-[0_24px_48px_-12px_rgb(0_0_0/0.45),0_8px_16px_-8px_rgb(0_0_0/0.3)] ring-1 ring-stone-900/10 sm:flex">
                            <span class="grid size-9 place-items-center rounded-lg bg-accent-50 text-accent-700"><x-icon name="bookmark" /></span>
                            <span><span class="block font-semibold">{{ __('ui.landing.mock.toast_title') }}</span><span class="text-xs text-stone-500">{{ __('ui.landing.mock.toast_text') }}</span></span>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Sources --}}
            <section class="border-b border-stone-200 bg-white">
                <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6">
                    <p class="text-center text-sm font-semibold text-stone-500">{{ __('ui.landing.sources_title') }}</p>
                    <ul class="mt-6 flex flex-wrap items-center justify-center gap-x-10 gap-y-6 sm:gap-x-12">
                        @foreach ($platforms as $platform)
                            <li class="flex h-9 items-center opacity-60 grayscale transition hover:opacity-100 hover:grayscale-0" data-source="{{ $platform['key'] }}" title="{{ $platform['name'] }}">
                                @if ($platform['logo'])
                                    <img src="{{ $platform['logo'] }}" alt="{{ $platform['name'] }}" class="{{ $logoHeights[$platform['key']] ?? 'h-6' }} w-auto">
                                @else
                                    <span class="text-xl font-extrabold tracking-tight text-stone-800">{{ $platform['name'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>

            {{-- Why --}}
            <section class="mx-auto max-w-7xl px-4 py-20 sm:px-6">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="text-sm font-semibold uppercase tracking-widest text-accent-700">{{ __('ui.landing.why.eyebrow') }}</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('ui.landing.why.title') }}</h2>
                    <p class="mt-4 text-lg leading-relaxed text-stone-600">{{ __('ui.landing.why.text') }}</p>
                </div>
                <ul class="mt-12 grid gap-6 md:grid-cols-3">
                    @foreach (['source' => 'radar', 'duplicates' => 'stack', 'fresh' => 'clock'] as $key => $icon)
                        <li class="rounded-2xl bg-white p-6 text-center shadow-sm ring-1 ring-stone-200">
                            <span class="mx-auto grid size-12 place-items-center rounded-2xl bg-accent-700 text-white"><x-icon :name="$icon" class="size-6" /></span>
                            <p class="mt-4 font-bold">{{ __("ui.landing.why.points.{$key}.title") }}</p>
                            <p class="mt-1 text-sm leading-relaxed text-stone-600">{{ __("ui.landing.why.points.{$key}.text") }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Features --}}
            <section id="funktioner" class="scroll-mt-20 border-y border-stone-200 bg-white">
                <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase tracking-widest text-accent-700">{{ __('ui.landing.nav.features') }}</p>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('ui.landing.features_title') }}</h2>
                    </div>
                    <ul class="mt-12 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($features as $key => $icon)
                            <li class="flex gap-4">
                                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-accent-50 text-accent-700 ring-1 ring-accent-100"><x-icon :name="$icon" class="size-5" /></span>
                                <div>
                                    <p class="font-bold">{{ __("ui.landing.features.{$key}.title") }}</p>
                                    <p class="mt-1 text-sm leading-relaxed text-stone-600">{{ __("ui.landing.features.{$key}.text") }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>

            {{-- CV matching --}}
            @if ($matching)
                <section id="cv" class="scroll-mt-20 mx-auto grid max-w-7xl items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-2">
                    <div>
                        <p class="inline-flex items-center gap-2 text-sm font-semibold uppercase tracking-widest text-accent-700"><x-icon name="sparkles" />{{ __('ui.landing.match.eyebrow') }}</p>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('ui.landing.match.title') }}</h2>
                        <p class="mt-4 text-lg leading-relaxed text-stone-600">{{ __('ui.landing.match.text') }}</p>
                        <ul class="mt-6 space-y-3">
                            @foreach (__('ui.landing.match.points') as $point)
                                <li class="flex gap-3 text-stone-700"><x-icon name="circle-check" class="mt-0.5 size-5 text-accent-600" />{{ $point }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="rounded-3xl bg-gradient-to-br from-accent-50 to-white p-6 ring-1 ring-accent-100 sm:p-8" aria-hidden="true">
                        <ul class="space-y-3">
                            @foreach (__('ui.landing.match.mock') as $match)
                                <li class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-200">
                                    <div class="flex items-center gap-3">
                                        <span @class(['grid size-11 shrink-0 place-items-center rounded-xl text-sm font-extrabold tabular-nums', 'bg-accent-600 text-white' => $match['score'] >= 80, 'bg-accent-100 text-accent-700' => $match['score'] >= 65 && $match['score'] < 80, 'bg-amber-100 text-amber-800' => $match['score'] < 65])>{{ $match['score'] }}</span>
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold">{{ $match['title'] }}</p>
                                            <span class="mt-1 block h-2 w-24 rounded-full bg-stone-200"></span>
                                        </div>
                                    </div>
                                    <p class="mt-3 text-sm leading-relaxed text-stone-600">{{ $match['reason'] }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>
            @endif

            {{-- How it works --}}
            <section id="sa-funkar-det" class="scroll-mt-20 bg-stone-900 text-white">
                <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase tracking-widest text-accent-300">{{ __('ui.landing.nav.how') }}</p>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('ui.landing.how_title') }}</h2>
                    </div>
                    <ol class="mt-12 grid gap-6 md:grid-cols-3">
                        @foreach (['account', 'search', 'apply'] as $i => $step)
                            <li class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10">
                                <span class="grid size-10 place-items-center rounded-full bg-accent-500 font-extrabold text-white">{{ $i + 1 }}</span>
                                <p class="mt-5 text-lg font-bold">{{ __("ui.landing.how.{$step}.title") }}</p>
                                <p class="mt-2 text-sm leading-relaxed text-stone-300">{{ __("ui.landing.how.{$step}.text") }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>

            {{-- FAQ --}}
            <section id="fragor" class="scroll-mt-20 mx-auto max-w-3xl px-4 py-20 sm:px-6">
                <h2 class="text-center text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('ui.landing.faq_title') }}</h2>
                <div class="mt-10 divide-y divide-stone-200 rounded-2xl bg-white shadow-sm ring-1 ring-stone-200">
                    @foreach ($faq as $key)
                        <details class="group px-6 py-5" @if ($loop->first) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold [&::-webkit-details-marker]:hidden">
                                {{ __("ui.landing.faq.{$key}.q") }}
                                <x-icon name="chevron-down" class="size-5 text-stone-400 transition group-open:rotate-180" />
                            </summary>
                            <p class="mt-3 text-sm leading-relaxed text-stone-600">{{ __("ui.landing.faq.{$key}.a", ['sources' => implode(', ', array_column($platforms, 'name'))]) }}</p>
                        </details>
                    @endforeach
                </div>
            </section>

            {{-- Call to action --}}
            <section class="mx-auto max-w-7xl px-4 pb-20 sm:px-6">
                <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-accent-600 to-accent-900 px-6 py-14 text-center text-white sm:px-12">
                    <svg class="pointer-events-none absolute left-1/2 top-1/2 size-[50rem] -translate-x-1/2 -translate-y-1/2 text-white/[0.07]" viewBox="0 0 200 200" fill="none" stroke="currentColor" aria-hidden="true">
                        <circle cx="100" cy="100" r="25"/><circle cx="100" cy="100" r="50"/><circle cx="100" cy="100" r="75"/><circle cx="100" cy="100" r="99"/>
                    </svg>
                    <div class="relative">
                        <h2 class="text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('ui.landing.final.title') }}</h2>
                        <p class="mx-auto mt-3 max-w-xl text-accent-50/90">{{ __('ui.landing.final.text') }}</p>
                        <div class="mt-8 flex flex-wrap justify-center gap-3">
                            @if ($registration)
                                <a href="{{ route('register') }}" class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-semibold text-accent-800 shadow-sm hover:bg-accent-50">{{ __('ui.landing.cta') }}<x-icon name="arrow-right" /></a>
                            @endif
                            <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-xl bg-white/10 px-5 py-3 text-sm font-semibold text-white ring-1 ring-white/25 hover:bg-white/20">{{ __('ui.landing.login') }}</a>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        @include('partials.footer')
    </body>
</html>
