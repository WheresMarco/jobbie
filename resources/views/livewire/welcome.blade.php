@php
    $steps = [
        ['icon' => 'briefcase', 'key' => 'find', 'route' => route('jobs')],
        ['icon' => 'bookmark', 'key' => 'track', 'route' => route('mine')],
    ];
    if ($matching) {
        $steps[] = ['icon' => 'sparkles', 'key' => 'match', 'route' => route('match')];
    }
@endphp
<div class="space-y-10">
    {{-- Hero --}}
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-accent-600 via-accent-700 to-accent-900 px-6 py-10 text-white shadow-xl shadow-accent-900/10 sm:px-10 sm:py-14">
        <svg class="pointer-events-none absolute -right-40 -top-40 size-[36rem] text-white/10" viewBox="0 0 200 200" fill="none" stroke="currentColor" aria-hidden="true">
            <circle cx="100" cy="100" r="25"/>
            <circle cx="100" cy="100" r="50"/>
            <circle cx="100" cy="100" r="75"/>
            <circle cx="100" cy="100" r="99"/>
        </svg>
        <div class="relative max-w-2xl">
            <p class="text-sm font-semibold uppercase tracking-widest text-accent-200">{{ __('ui.welcome.eyebrow') }}</p>
            <h1 class="mt-3 text-3xl font-extrabold leading-tight tracking-tight sm:text-5xl">
                {{ $name !== '' ? __('ui.welcome.greeting', ['name' => $name]) : __('ui.welcome.greeting_anonymous') }}
            </h1>
            <p class="mt-4 text-lg leading-relaxed text-accent-50/90">{{ __('ui.welcome.intro') }}</p>

            @if ($postings > 0)
                <dl class="mt-8 flex flex-wrap gap-x-10 gap-y-4" data-numbers>
                    <div>
                        <dt class="text-sm text-accent-200">{{ __('ui.welcome.numbers.postings') }}</dt>
                        <dd class="text-3xl font-extrabold tabular-nums">{{ number_format($postings, 0, ',', ' ') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-accent-200">{{ __('ui.welcome.numbers.employers') }}</dt>
                        <dd class="text-3xl font-extrabold tabular-nums">{{ number_format($employers, 0, ',', ' ') }}</dd>
                    </div>
                </dl>
            @endif

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('jobs') }}" wire:navigate
                   class="inline-flex items-center gap-2 rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-accent-800 shadow-sm hover:bg-accent-50">
                    {{ __('ui.welcome.cta') }}
                    <x-icon name="arrow-right" />
                </a>
                @if ($matching)
                    <a href="{{ route('match') }}" wire:navigate
                       class="inline-flex items-center gap-2 rounded-lg bg-white/10 px-5 py-2.5 text-sm font-semibold text-white ring-1 ring-white/25 hover:bg-white/20">
                        <x-icon name="sparkles" />
                        {{ __('ui.welcome.cta_match') }}
                    </a>
                @endif
            </div>
        </div>
    </section>

    {{-- Getting started --}}
    <section aria-labelledby="steps-heading">
        <h2 id="steps-heading" class="text-xl font-bold tracking-tight">{{ __('ui.welcome.steps_title') }}</h2>
        <ol @class(['mt-4 grid gap-4', 'md:grid-cols-3' => count($steps) === 3, 'md:grid-cols-2' => count($steps) === 2])>
            @foreach ($steps as $i => $step)
                <li>
                    <a href="{{ $step['route'] }}" wire:navigate
                       class="group flex h-full flex-col rounded-2xl border border-stone-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-accent-300 hover:shadow-md">
                        <span class="flex items-center justify-between">
                            <span class="grid size-11 place-items-center rounded-xl bg-accent-50 text-accent-700 ring-1 ring-accent-100">
                                <x-icon :name="$step['icon']" class="size-5" />
                            </span>
                            <span class="text-4xl font-extrabold text-stone-200 tabular-nums" aria-hidden="true">{{ $i + 1 }}</span>
                        </span>
                        <span class="mt-4 font-bold">{{ __("ui.welcome.steps.{$step['key']}.title") }}</span>
                        <span class="mt-1 flex-1 text-sm leading-relaxed text-stone-600">{{ __("ui.welcome.steps.{$step['key']}.text") }}</span>
                        <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-accent-700">
                            {{ __("ui.welcome.steps.{$step['key']}.link") }}
                            <x-icon name="arrow-right" class="size-4 transition group-hover:translate-x-0.5" />
                        </span>
                    </a>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Good to know --}}
    <section aria-labelledby="facts-heading" class="rounded-2xl border border-stone-200 bg-white p-6">
        <h2 id="facts-heading" class="font-bold">{{ __('ui.welcome.facts_title') }}</h2>
        <ul class="mt-4 grid gap-5 text-sm sm:grid-cols-3">
            @foreach (['radar' => 'source', 'clock' => 'fresh', 'shield-check' => 'privacy'] as $icon => $key)
                <li class="flex gap-3">
                    <x-icon :name="$icon" class="mt-0.5 size-5 text-accent-600" />
                    <div>
                        <p class="font-semibold">{{ __("ui.welcome.facts.{$key}.title") }}</p>
                        <p class="mt-0.5 text-stone-600">{{ __("ui.welcome.facts.{$key}.text") }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
</div>
