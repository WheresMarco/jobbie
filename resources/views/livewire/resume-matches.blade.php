<div @if ($resume?->status->busy()) wire:poll.3s @endif>
    <x-page-header icon="sparkles" :title="__('ui.match.title')" :subtitle="__('ui.match.intro')" />

    @unless ($enabled)
        <x-empty-state icon="lock" class="mt-6" :title="__('ui.match.disabled')" :hint="__('ui.match.disabled_hint')" />
    @else
        @if ($resume === null)
            <div class="mt-6 grid gap-4 lg:grid-cols-5">
                <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm lg:col-span-3">
                    <h2 class="flex items-center gap-2 font-bold"><x-icon name="file-up" class="size-5 text-accent-600" />{{ __('ui.match.upload_title') }}</h2>
                    <div class="mt-4">@include('livewire.partials.resume-upload')</div>
                </section>
                <section class="rounded-2xl bg-accent-50 p-6 ring-1 ring-accent-100 lg:col-span-2" aria-labelledby="how-heading">
                    <h2 id="how-heading" class="font-bold text-accent-900">{{ __('ui.match.how_title') }}</h2>
                    <ol class="mt-4 space-y-4 text-sm">
                        @foreach (['upload', 'read', 'score'] as $i => $step)
                            <li class="flex gap-3">
                                <span class="grid size-7 shrink-0 place-items-center rounded-full bg-accent-700 text-xs font-bold text-white">{{ $i + 1 }}</span>
                                <div>
                                    <p class="font-semibold text-accent-950">{{ __("ui.match.how.{$step}.title") }}</p>
                                    <p class="mt-0.5 text-accent-900/80">{{ __("ui.match.how.{$step}.text") }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>
        @else
            <div class="mt-6 grid gap-4 lg:grid-cols-3">
                <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm lg:col-span-2" aria-labelledby="profile-heading" data-profile>
                    @if ($profile)
                        <h2 id="profile-heading" class="text-lg font-semibold">{{ $profile->headline }}</h2>
                        <p class="mt-1 text-sm text-stone-500">
                            {{ __("ui.match.seniority.{$profile->seniority}") }}@if ($profile->years !== null) · {{ trans_choice('ui.match.years', $profile->years, ['count' => $profile->years]) }}@endif
                            @if ($profile->locations !== []) · {{ implode(', ', $profile->locations) }}@endif
                            @if ($profile->remote !== 'any') · {{ __("ui.match.remote.{$profile->remote}") }}@endif
                        </p>
                        <p class="mt-3 text-sm leading-relaxed text-stone-700">{{ $profile->summary }}</p>
                        @foreach (['roles' => $profile->roles, 'skills' => $profile->skills] as $list => $values)
                            <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-stone-500">{{ __("ui.match.{$list}") }}</h3>
                            <ul class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs" data-list="{{ $list }}">
                                @foreach ($values as $value)
                                    @php($own = \App\Matching\Profile::contains($additions[$list], $value))
                                    <li @class([
                                            'inline-flex items-center gap-0.5 rounded-full py-0.5 pl-2',
                                            'pr-2' => $resume->status->busy(),
                                            'pr-1' => ! $resume->status->busy(),
                                            'bg-accent-50 text-accent-700' => $own,
                                            'bg-stone-100 text-stone-700' => ! $own,
                                        ]) @if ($own) title="{{ __('ui.match.own') }}" data-own @endif>
                                        {{ $value }}
                                        @unless ($resume->status->busy())
                                            <button type="button" wire:click="removeItem(@js($list), @js($value))"
                                                    @class(['rounded-full px-1 leading-none', 'text-accent-700 hover:bg-accent-100' => $own, 'text-stone-400 hover:bg-stone-200 hover:text-stone-700' => ! $own])
                                                    aria-label="{{ __('ui.match.remove_item', ['item' => $value]) }}">&times;</button>
                                        @endunless
                                    </li>
                                @endforeach
                                @unless ($resume->status->busy())
                                    <li x-data="{ open: false }" wire:key="add-{{ $list }}">
                                        <button type="button" x-show="! open" @click="open = true; $nextTick(() => $refs.input.focus())"
                                                class="rounded-full border border-dashed border-stone-300 px-2 py-0.5 text-stone-600 hover:border-accent-600 hover:text-accent-700">+ {{ __('ui.match.add') }}</button>
                                        <form x-show="open" style="display: none" wire:submit="add(@js($list))" @keydown.escape="open = false" class="inline-flex items-center gap-1">
                                            <input type="text" x-ref="input" wire:model="adding.{{ $list }}" maxlength="{{ \App\Livewire\ResumeMatches::MAX_ADDITION_LENGTH }}"
                                                   aria-label="{{ __("ui.match.add_{$list}") }}" placeholder="{{ __("ui.match.add_{$list}") }}"
                                                   class="w-40 rounded-full border border-stone-300 px-2 py-0.5 text-xs focus:border-accent-600 focus:outline-none">
                                            <button type="submit" class="rounded-full bg-stone-900 px-2 py-0.5 font-medium text-white hover:bg-stone-700">{{ __('ui.match.add') }}</button>
                                            <button type="button" @click="open = false" class="px-1 text-stone-500 hover:text-stone-800" aria-label="{{ __('ui.match.add_cancel') }}">&times;</button>
                                        </form>
                                    </li>
                                @endunless
                            </ul>
                            @error("adding.{$list}") <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        @endforeach
                    @else
                        <h2 id="profile-heading" class="font-semibold">{{ $resume->filename }}</h2>
                        <p class="mt-1 text-sm text-stone-500">{{ __('ui.match.no_profile') }}</p>
                    @endif
                </section>

                <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5 text-sm shadow-sm">
                    <form wire:submit="savePreferences">
                        <label for="preferences" class="font-medium">{{ __('ui.match.preferences') }}</label>
                        <textarea id="preferences" wire:model="preferences" rows="3" maxlength="{{ \App\Livewire\ResumeMatches::MAX_PREFERENCES }}"
                                  placeholder="{{ __('ui.match.preferences_placeholder') }}" @disabled($resume->status->busy())
                                  class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-accent-600 focus:outline-none disabled:bg-stone-50"></textarea>
                        @error('preferences') <p class="mt-1 text-red-700">{{ $message }}</p> @enderror
                        <button type="submit" @disabled($resume->status->busy())
                                class="mt-2 rounded-lg border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:opacity-50">{{ __('ui.match.save_preferences') }}</button>
                    </form>

                    <details class="border-t border-stone-100 pt-4">
                        <summary class="cursor-pointer font-medium">{{ __('ui.match.replace') }}</summary>
                        <p class="mt-1 text-stone-500">{{ __('ui.match.current_file', ['file' => $resume->filename]) }}</p>
                        <div class="mt-3">@include('livewire.partials.resume-upload', ['withPreferences' => false])</div>
                    </details>

                    <div class="border-t border-stone-100 pt-4">
                        <button type="button" wire:click="remove" wire:confirm="{{ __('ui.match.remove_confirm') }}"
                                class="text-stone-500 hover:text-red-700 hover:underline">{{ __('ui.match.remove') }}</button>
                    </div>
                </section>
            </div>

            <div class="mt-4 rounded-2xl border border-stone-200 bg-white px-5 py-3 text-sm shadow-sm" role="status" data-status="{{ $resume->status->value }}">
                @switch($resume->status)
                    @case(\App\Enums\ResumeStatus::Analyzing)
                        <p class="flex items-center gap-2"><span class="inline-block size-2 animate-pulse rounded-full bg-accent-600" aria-hidden="true"></span>{{ __('ui.match.analyzing') }}</p>
                        @break
                    @case(\App\Enums\ResumeStatus::Matching)
                        <p class="flex items-center gap-2"><span class="inline-block size-2 animate-pulse rounded-full bg-accent-600" aria-hidden="true"></span>
                            {{ $resume->to_score > 0 ? __('ui.match.scoring', ['scored' => $resume->scored, 'total' => $resume->to_score]) : __('ui.match.searching') }}</p>
                        @if ($resume->to_score > 0)
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-stone-100">
                                <div class="h-full rounded-full bg-accent-600 transition-all" style="width: {{ min(100, intdiv(100 * $resume->scored, $resume->to_score)) }}%"></div>
                            </div>
                        @endif
                        @break
                    @case(\App\Enums\ResumeStatus::Failed)
                        <p class="text-red-700">{{ $resume->error ?? __('ui.match.error.scoring') }}</p>
                        @break
                    @default
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p>{{ __('ui.match.ready', ['strong' => $strongCount, 'total' => $totalCount, 'min' => $minScore]) }}
                                @if ($resume->matched_at)<span class="text-stone-500">· {{ __('ui.match.matched_at', ['time' => $resume->matched_at->locale(app()->getLocale())->diffForHumans()]) }}</span>@endif</p>
                        </div>
                @endswitch
                @if (! $resume->status->busy() && $profile)
                    @if ($resume->edited)
                        <p class="mt-2 text-amber-800" data-edited>{{ __('ui.match.edited') }}</p>
                    @endif
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="searchAgain" class="rounded-lg bg-accent-700 px-3 py-1.5 font-semibold text-white hover:bg-accent-800">{{ $resume->edited ? __('ui.match.rematch') : __('ui.match.search_again') }}</button>
                        <span class="text-stone-500">{{ $resume->edited ? __('ui.match.rematch_hint') : __('ui.match.search_again_hint') }}</span>
                    </div>
                    @error('run') <p class="mt-1 text-red-700">{{ $message }}</p> @enderror
                @endif
            </div>

            @if ($totalCount > 0)
                <div class="mt-6 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="flex items-center gap-2 text-lg font-bold tracking-tight"><x-icon name="sparkles" class="text-accent-600" />{{ __('ui.match.matches') }}</h2>
                    <label class="flex items-center gap-2 text-sm text-stone-600">
                        <input type="checkbox" wire:model.live="all" class="rounded border-stone-300">
                        {{ __('ui.match.show_weak', ['min' => $minScore, 'count' => $totalCount - $strongCount]) }}
                    </label>
                </div>

                @if ($matches === [])
                    <x-empty-state icon="search-x" class="mt-3" :title="__('ui.match.no_strong_title')" :hint="__('ui.match.no_strong')" />
                @else
                    <ul class="mt-3 divide-y divide-stone-100 rounded-2xl border border-stone-200 bg-white px-4 shadow-sm">
                        @foreach ($matches as $match)
                            @include('livewire.partials.posting', ['posting' => $match->jobPosting, 'match' => $match, 'terms' => [], 'showEmployer' => true, 'userMarks' => $userMarks])
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
            @endif
        @endif
    @endunless
</div>
