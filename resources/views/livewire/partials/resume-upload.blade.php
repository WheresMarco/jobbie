@php($withPreferences ??= true)
<form wire:submit="upload" class="space-y-3 text-sm">
    <div>
        <label for="cv" class="font-medium">{{ __('ui.match.cv_label') }}</label>
        <input id="cv" type="file" wire:model="cv" accept="application/pdf,.pdf"
               class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-accent-50 file:px-3 file:py-1.5 file:font-semibold file:text-accent-800 hover:file:bg-accent-100">
        <p class="mt-1 text-stone-500">{{ __('ui.match.cv_hint', ['mb' => $maxUploadMb]) }}</p>
        <p wire:loading wire:target="cv" class="mt-1 text-stone-500">{{ __('ui.match.uploading') }}</p>
        @error('cv') <p class="mt-1 text-red-700">{{ $message }}</p> @enderror
    </div>
    @if ($withPreferences)
        <div>
            <label for="new-preferences" class="font-medium">{{ __('ui.match.preferences') }}</label>
            <textarea id="new-preferences" wire:model="preferences" rows="3" maxlength="{{ \App\Livewire\ResumeMatches::MAX_PREFERENCES }}"
                      placeholder="{{ __('ui.match.preferences_placeholder') }}"
                      class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-accent-600 focus:outline-none"></textarea>
            @error('preferences') <p class="mt-1 text-red-700">{{ $message }}</p> @enderror
        </div>
    @endif
    <p class="flex gap-2 text-stone-500"><x-icon name="shield-check" class="mt-0.5 text-accent-600" />{{ __('ui.match.privacy') }}</p>
    <button type="submit" wire:loading.attr="disabled" wire:target="cv,upload"
            class="inline-flex items-center gap-2 rounded-lg bg-accent-700 px-4 py-2 font-semibold text-white hover:bg-accent-800 disabled:opacity-50"><x-icon name="sparkles" />{{ __('ui.match.submit') }}</button>
</form>
