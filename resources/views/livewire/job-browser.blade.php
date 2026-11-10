@php
    /** @var \App\Search\Facets $facets */
    /** @var \App\Search\JobFilters $filters */
    $countries = $facets->countries;
    if ($country !== '' && ! isset($countries[strtoupper($country)])) {
        $countries[strtoupper($country)] = 0;
    }
    $cities = $facets->cities;
    if ($city !== '' && ! isset($cities[$city])) {
        $cities[$city] = 0;
    }
    $departments = $facets->departments;
    if ($department !== '' && ! isset($departments[$department])) {
        $departments[$department] = 0;
    }
@endphp
<div x-data="{ filtersOpen: false }" class="lg:grid lg:grid-cols-[17rem_1fr] lg:gap-8">
    {{-- Filters --}}
    <aside class="lg:block" :class="filtersOpen ? 'block' : 'hidden'" aria-label="{{ __('ui.filters.title') }}">
        <div class="space-y-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm lg:sticky lg:top-20">
            <div class="flex items-center justify-between">
                <h2 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-stone-500"><x-icon name="sliders" />{{ __('ui.filters.title') }}</h2>
                @unless ($filters->isEmpty())
                    <button type="button" wire:click="clearFilters" class="text-sm text-accent-700 hover:underline">{{ __('ui.filters.clear') }}</button>
                @endunless
            </div>

            @if ($userMarks)
                <div class="space-y-1.5">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="unseen" class="rounded border-stone-300 text-accent-600">
                        <span class="flex-1 font-medium">{{ __('ui.filters.unseen') }}</span>
                        <span class="text-xs tabular-nums text-stone-500" data-facet="unseen">{{ $facets->unseen }}</span>
                    </label>
                    @if ($facets->unseen > 0 || $unseen)
                        <button type="button" wire:click="markAllSeen" wire:confirm="{{ __('ui.marks.mark_all_seen_confirm') }}"
                                class="text-sm text-accent-700 hover:underline">{{ __('ui.marks.mark_all_seen') }}</button>
                    @endif
                </div>
            @endif

            <label class="block">
                <span class="mb-1 block text-sm font-medium">{{ __('ui.filters.country') }}</span>
                <select wire:model.live="country" class="w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm">
                    <option value="">{{ __('ui.filters.all') }}</option>
                    @foreach ($countries as $code => $count)
                        <option value="{{ $code }}">{{ \App\Livewire\JobBrowser::countryName((string) $code) }} ({{ $count }})</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-sm font-medium">{{ __('ui.filters.city') }}</span>
                <select wire:model.live="city" class="w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm">
                    <option value="">{{ __('ui.filters.all') }}</option>
                    @foreach ($cities as $name => $count)
                        <option value="{{ $name }}">{{ $name }} ({{ $count }})</option>
                    @endforeach
                </select>
            </label>

            <fieldset>
                <legend class="mb-1 text-sm font-medium">{{ __('ui.filters.remote') }}</legend>
                @foreach (\App\Search\JobFilters::REMOTE_OPTIONS as $option)
                    <label class="flex items-center gap-2 py-0.5 text-sm">
                        <input type="checkbox" wire:model.live="remote" value="{{ $option }}" class="rounded border-stone-300 text-accent-600">
                        <span class="flex-1">{{ __("ui.remote.{$option}") }}</span>
                        <span class="text-xs tabular-nums text-stone-500" data-facet="remote-{{ $option }}">{{ $facets->remote[$option] ?? 0 }}</span>
                    </label>
                @endforeach
            </fieldset>

            @if ($departments !== [])
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('ui.filters.department') }}</span>
                    <select wire:model.live="department" class="w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm">
                        <option value="">{{ __('ui.filters.all') }}</option>
                        @foreach ($departments as $name => $count)
                            <option value="{{ $name }}">{{ $name }} ({{ $count }})</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <fieldset>
                <legend class="mb-1 text-sm font-medium">{{ __('ui.filters.published') }}</legend>
                <label class="flex items-center gap-2 py-0.5 text-sm">
                    <input type="radio" wire:model.live="published" value="" class="border-stone-300 text-accent-600">
                    <span class="flex-1">{{ __('ui.filters.any_time') }}</span>
                </label>
                @foreach (\App\Search\JobFilters::PUBLISHED_OPTIONS as $days)
                    <label class="flex items-center gap-2 py-0.5 text-sm">
                        <input type="radio" wire:model.live="published" value="{{ $days }}" class="border-stone-300 text-accent-600">
                        <span class="flex-1">{{ trans_choice('ui.filters.within_days', $days, ['days' => $days]) }}</span>
                        <span class="text-xs tabular-nums text-stone-500" data-facet="published-{{ $days }}">{{ $facets->published[$days] ?? 0 }}</span>
                    </label>
                @endforeach
            </fieldset>
        </div>
    </aside>

    {{-- Results --}}
    <section class="mt-4 lg:mt-0" aria-live="polite">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <label class="relative block w-full">
                <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-stone-400" />
                <input type="search" wire:model.live.debounce.300ms="q" placeholder="{{ __('ui.jobs.search_placeholder') }}"
                       aria-label="{{ __('ui.jobs.search_placeholder') }}"
                       class="w-full rounded-xl border border-stone-300 bg-white py-3 pl-11 pr-4 shadow-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30">
            </label>
            <div class="flex shrink-0 gap-2">
                <button type="button" @click="filtersOpen = ! filtersOpen"
                        class="flex items-center gap-2 rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm font-medium shadow-sm lg:hidden"><x-icon name="sliders" />{{ __('ui.filters.title') }}</button>
                <div class="inline-flex rounded-xl border border-stone-300 bg-white p-1 text-sm shadow-sm" role="group" aria-label="{{ __('ui.jobs.view') }}">
                    <button type="button" wire:click="$set('view', 'grouped')" aria-pressed="{{ $grouped ? 'true' : 'false' }}"
                            @class(['flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5', 'bg-accent-700 font-medium text-white' => $grouped, 'text-stone-600 hover:text-stone-900' => ! $grouped])><x-icon name="building" class="hidden sm:block" />{{ __('ui.jobs.view_grouped') }}</button>
                    <button type="button" wire:click="$set('view', 'flat')" aria-pressed="{{ $grouped ? 'false' : 'true' }}"
                            @class(['flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5', 'bg-accent-700 font-medium text-white' => ! $grouped, 'text-stone-600 hover:text-stone-900' => $grouped])><x-icon name="list" class="hidden sm:block" />{{ __('ui.jobs.view_flat') }}</button>
                </div>
            </div>
        </div>

        <p class="mt-4 text-sm text-stone-600" data-total="{{ $total }}">
            {{ trans_choice('ui.jobs.count', $total, ['count' => number_format($total, 0, ',', ' ')]) }}
            <span wire:loading class="ml-2 text-stone-400">{{ __('ui.jobs.loading') }}</span>
        </p>

        @if ($total === 0)
            @if ($filters->isEmpty())
                <x-empty-state icon="radar" class="mt-6" :title="__('ui.jobs.empty_database')" :hint="__('ui.jobs.empty_database_hint')" />
            @else
                <x-empty-state icon="search-x" class="mt-6" :title="__('ui.jobs.empty_filters')" :hint="__('ui.jobs.empty_filters_hint')">
                    <button type="button" wire:click="clearFilters" class="rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-100">{{ __('ui.filters.clear') }}</button>
                </x-empty-state>
            @endif
        @elseif ($grouped)
            @php($openNow = array_values(array_map(fn ($g) => $g->employer, array_filter($groups, fn ($g) => $g->open))))
            <div class="mt-4 space-y-3">
                @foreach ($groups as $group)
                    <section class="rounded-2xl border border-stone-200 bg-white shadow-sm" wire:key="group-{{ md5($group->employer) }}">
                        <div class="flex items-center">
                        <button type="button" aria-expanded="{{ $group->open ? 'true' : 'false' }}"
                                wire:click="toggleGroup(@js($group->employer), @js($openNow))"
                                class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-left">
                            <x-icon name="chevron-right" :class="'text-stone-400 transition-transform'.($group->open ? ' rotate-90' : '')" />
                            <span class="font-semibold">{{ \App\Search\Highlighter::highlight($group->employer, $terms) }}</span>
                            <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium tabular-nums">{{ $group->total }}</span>
                            @if ($group->remote > 0)
                                <span class="text-xs text-accent-700">{{ trans_choice('ui.jobs.remote_count', $group->remote, ['count' => $group->remote]) }}</span>
                            @endif
                            <span class="min-w-0 flex-1 truncate text-sm text-stone-500">{{ implode(', ', array_slice($group->cities, 0, 5)) }}</span>
                        </button>
                        @if ($userMarks && $group->employer !== '')
                            <button type="button" wire:click="hideEmployer(@js($group->employer))"
                                    wire:confirm="{{ __('ui.marks.hide_employer_confirm', ['employer' => $group->employer]) }}"
                                    class="mr-3 shrink-0 rounded-md border border-stone-300 px-2 py-1 text-xs font-medium text-stone-600 hover:bg-stone-100"
                                    data-hide-employer>{{ __('ui.marks.action.not_relevant') }}</button>
                        @endif
                        </div>
                        @if ($group->open)
                            <ul class="divide-y divide-stone-100 border-t border-stone-100 px-4">
                                @foreach ($group->postings as $posting)
                                    @include('livewire.partials.posting', ['posting' => $posting, 'terms' => $terms, 'showEmployer' => false, 'userMarks' => $userMarks])
                                @endforeach
                            </ul>
                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-stone-100 px-4 py-2 text-sm">
                                <span class="text-stone-500">
                                    @if ($group->total > count($group->postings))
                                        {{ __('ui.jobs.more_in_group', ['count' => $group->total - count($group->postings)]) }}
                                    @endif
                                </span>
                                @if ($group->siteUrl)
                                    <a href="{{ $group->siteUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-accent-700 hover:underline">{{ __('ui.jobs.career_site') }}<x-icon name="external-link" class="size-3.5" /></a>
                                @endif
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        @else
            <ul class="mt-4 divide-y divide-stone-100 rounded-2xl border border-stone-200 bg-white px-4 shadow-sm">
                @foreach ($postings as $posting)
                    @include('livewire.partials.posting', ['posting' => $posting, 'terms' => $terms, 'showEmployer' => true, 'userMarks' => $userMarks])
                @endforeach
            </ul>
        @endif

        @if ($hasMore)
            <div class="mt-6 text-center">
                <button type="button" wire:click="showMore" class="rounded-xl border border-stone-300 bg-white px-5 py-2 text-sm font-semibold shadow-sm hover:bg-stone-100">
                    {{ __('ui.jobs.show_more') }}
                </button>
            </div>
        @endif
    </section>
</div>
