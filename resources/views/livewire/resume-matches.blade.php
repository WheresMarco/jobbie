<div @if ($resume?->status->busy()) wire:poll.3s @endif>
    <h1 class="text-xl font-semibold tracking-tight">{{ __('ui.match.title') }}</h1>
    <p class="mt-1 max-w-3xl text-sm text-stone-600">{{ __('ui.match.intro') }}</p>

    @unless ($enabled)
        <div class="mt-6 rounded-xl border border-dashed border-stone-300 bg-white p-10 text-center">
            <p class="font-medium">{{ __('ui.match.disabled') }}</p>
            <p class="mt-1 text-sm text-stone-500">{{ __('ui.match.disabled_hint') }}</p>
        </div>
    @else
        @if ($resume === null)
            <section class="mt-6 max-w-2xl rounded-xl border border-stone-200 bg-white p-5">
                @include('livewire.partials.resume-upload')
            </section>
        @else
            <div class="mt-6 grid gap-4 lg:grid-cols-3">
                <section class="rounded-xl border border-stone-200 bg-white p-5 lg:col-span-2" aria-labelledby="profile-heading" data-profile>
                    @if ($profile)
                        <h2 id="profile-heading" class="text-lg font-semibold">{{ $profile->headline }}</h2>
                        <p class="mt-1 text-sm text-stone-500">
                            {{ __("ui.match.seniority.{$profile->seniority}") }}@if ($profile->years !== null) · {{ trans_choice('ui.match.years', $profile->years, ['count' => $profile->years]) }}@endif
                            @if ($profile->locations !== []) · {{ implode(', ', $profile->locations) }}@endif
                            @if ($profile->remote !== 'any') · {{ __("ui.match.remote.{$profile->remote}") }}@endif
                        </p>
                        <p class="mt-3 text-sm leading-relaxed text-stone-700">{{ $profile->summary }}</p>
                        @foreach (['roles' => $profile->roles, 'skills' => $profile->skills] as $label => $values)
                            @if ($values !== [])
                                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-stone-500">{{ __("ui.match.{$label}") }}</h3>
                                <ul class="mt-1.5 flex flex-wrap gap-1.5 text-xs">
                                    @foreach ($values as $value)
                                        <li class="rounded-full bg-stone-100 px-2 py-0.5 text-stone-700">{{ $value }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        @endforeach
                    @else
                        <h2 id="profile-heading" class="font-semibold">{{ $resume->filename }}</h2>
                        <p class="mt-1 text-sm text-stone-500">{{ __('ui.match.no_profile') }}</p>
                    @endif
                </section>

                <section class="space-y-4 rounded-xl border border-stone-200 bg-white p-5 text-sm">
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

            <div class="mt-4 rounded-xl border border-stone-200 bg-white px-5 py-3 text-sm" role="status" data-status="{{ $resume->status->value }}">
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
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="searchAgain" class="rounded-lg bg-stone-900 px-3 py-1.5 font-medium text-white hover:bg-stone-700">{{ __('ui.match.search_again') }}</button>
                        <span class="text-stone-500">{{ __('ui.match.search_again_hint') }}</span>
                    </div>
                    @error('run') <p class="mt-1 text-red-700">{{ $message }}</p> @enderror
                @endif
            </div>

            @if ($totalCount > 0)
                <div class="mt-6 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">{{ __('ui.match.matches') }}</h2>
                    <label class="flex items-center gap-2 text-sm text-stone-600">
                        <input type="checkbox" wire:model.live="all" class="rounded border-stone-300">
                        {{ __('ui.match.show_weak', ['min' => $minScore, 'count' => $totalCount - $strongCount]) }}
                    </label>
                </div>

                @if ($matches === [])
                    <div class="mt-3 rounded-xl border border-dashed border-stone-300 bg-white p-8 text-center text-sm">{{ __('ui.match.no_strong') }}</div>
                @else
                    <ul class="mt-3 divide-y divide-stone-100 rounded-xl border border-stone-200 bg-white px-4">
                        @foreach ($matches as $match)
                            @include('livewire.partials.posting', ['posting' => $match->jobPosting, 'match' => $match, 'terms' => [], 'showEmployer' => true, 'userMarks' => $userMarks])
                        @endforeach
                    </ul>
                @endif

                @if ($hasMore)
                    <div class="mt-6 text-center">
                        <button type="button" wire:click="showMore" class="rounded-lg border border-stone-300 bg-white px-5 py-2 text-sm font-medium hover:bg-stone-100">
                            {{ __('ui.jobs.show_more') }}
                        </button>
                    </div>
                @endif
            @endif
        @endif
    @endunless
</div>
