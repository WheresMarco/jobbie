@php
    $field = 'w-full rounded-md border border-stone-300 px-3 py-2 text-sm outline-none focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30';
@endphp
<div class="mx-auto max-w-2xl">
    <h1 class="text-xl font-semibold tracking-tight">{{ __('ui.profile.title') }}</h1>
    <p class="mt-2 text-sm text-stone-600">{{ __('ui.profile.intro', ['length' => \App\Matching\Criteria::COMPOUND_MIN_LENGTH]) }}</p>

    <form wire:submit="save" class="mt-6 space-y-5 rounded-xl border border-stone-200 bg-white p-6">
        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('ui.profile.titles') }}</span>
            <textarea wire:model="titles" rows="3" class="{{ $field }}"></textarea>
            <span class="mt-1 block text-xs text-stone-500">{{ __('ui.profile.titles_hint') }}</span>
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('ui.profile.skills') }}</span>
            <textarea wire:model="skills" rows="4" class="{{ $field }}"></textarea>
            <span class="mt-1 block text-xs text-stone-500">{{ __('ui.profile.skills_hint', ['count' => \App\Matching\Matcher::SKILLS_FOR_FULL_SCORE]) }}</span>
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('ui.profile.excluded') }}</span>
            <textarea wire:model="excluded" rows="2" class="{{ $field }}"></textarea>
            <span class="mt-1 block text-xs text-stone-500">{{ __('ui.profile.excluded_hint') }}</span>
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('ui.profile.cities') }}</span>
            <input type="text" wire:model="cities" class="{{ $field }}">
            <span class="mt-1 block text-xs text-stone-500">{{ __('ui.profile.cities_hint') }}</span>
        </label>

        <fieldset>
            <legend class="mb-1 text-sm font-medium">{{ __('ui.profile.remote') }}</legend>
            @foreach (\App\Livewire\ProfilePage::REMOTE_OPTIONS as $option)
                <label class="flex items-center gap-2 py-0.5 text-sm">
                    <input type="checkbox" wire:model="remote" value="{{ $option }}" class="rounded border-stone-300 text-accent-600">
                    <span>{{ __("ui.remote.{$option}") }}</span>
                </label>
            @endforeach
            <span class="mt-1 block text-xs text-stone-500">{{ __('ui.profile.remote_hint') }}</span>
        </fieldset>

        <p class="text-xs text-stone-500">{{ __('ui.profile.weights', [
            'title' => \App\Matching\Matcher::TITLE_WEIGHT,
            'skills' => \App\Matching\Matcher::SKILLS_WEIGHT,
            'location' => \App\Matching\Matcher::LOCATION_WEIGHT,
        ]) }}</p>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-800">
                <span wire:loading.remove wire:target="save">{{ __('ui.profile.save') }}</span>
                <span wire:loading wire:target="save">{{ __('ui.jobs.loading') }}</span>
            </button>
            @if ($saved)
                <span class="text-sm text-accent-700" role="status" data-saved>{{ __('ui.profile.saved') }}</span>
            @endif
        </div>
    </form>

    @if ($profile)
        <div class="mt-4 rounded-xl border border-stone-200 bg-white p-4 text-sm text-stone-600" data-profile-status>
            @if ($matching === null)
                {{ __('ui.profile.scoring') }}
            @else
                <p>{{ trans_choice('ui.profile.matching', $matching, ['count' => $matching, 'threshold' => config('radar.match_threshold')]) }}
                    {{ __('ui.profile.scored_at', ['time' => $profile->scored_at?->locale(app()->getLocale())->diffForHumans()]) }}</p>
                @if ($matching > 0)
                    <a href="{{ route('jobs', ['matching' => 1]) }}" wire:navigate class="mt-2 inline-block text-accent-700 hover:underline">{{ __('ui.profile.show_matching') }}</a>
                @endif
            @endif
        </div>
    @endif
</div>
