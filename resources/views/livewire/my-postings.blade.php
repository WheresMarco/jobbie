<div>
    <h1 class="text-xl font-semibold tracking-tight">{{ __('ui.nav.mine') }}</h1>

    <div class="mt-4 inline-flex flex-wrap rounded-lg border border-stone-300 bg-white p-0.5 text-sm" role="group" aria-label="{{ __('ui.marks.label') }}">
        @foreach (\App\Enums\MarkStatus::cases() as $option)
            <button type="button" wire:click="$set('status', '{{ $option->value }}')" aria-pressed="{{ $current === $option ? 'true' : 'false' }}"
                    @class(['flex items-center gap-2 rounded-md px-3 py-1.5', 'bg-stone-900 text-white' => $current === $option])>
                {{ __("ui.marks.tab.{$option->value}") }}
                <span @class(['text-xs tabular-nums', 'text-stone-300' => $current === $option, 'text-stone-500' => $current !== $option])
                      data-count="{{ $option->value }}">{{ $counts[$option->value] }}</span>
            </button>
        @endforeach
    </div>

    <span wire:loading class="ml-2 text-sm text-stone-400">{{ __('ui.jobs.loading') }}</span>

    @if ($postings === [])
        <div class="mt-6 rounded-xl border border-dashed border-stone-300 bg-white p-10 text-center">
            <p class="font-medium">{{ __("ui.marks.empty.{$current->value}") }}</p>
            <a href="{{ route('jobs') }}" wire:navigate class="mt-3 inline-block text-sm text-accent-700 hover:underline">{{ __('ui.marks.to_jobs') }}</a>
        </div>
    @else
        <ul class="mt-4 divide-y divide-stone-100 rounded-xl border border-stone-200 bg-white px-4">
            @foreach ($postings as $posting)
                @include('livewire.partials.posting', ['posting' => $posting, 'terms' => [], 'showEmployer' => true, 'userMarks' => $userMarks])
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
</div>
