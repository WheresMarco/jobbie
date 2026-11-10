<div>
    <x-page-header icon="bookmark" :title="__('ui.nav.mine')" :subtitle="__('ui.marks.intro')" />

    <div class="mt-6 inline-flex flex-wrap rounded-xl border border-stone-300 bg-white p-1 text-sm shadow-sm" role="group" aria-label="{{ __('ui.marks.label') }}">
        @foreach (\App\Enums\MarkStatus::cases() as $option)
            <button type="button" wire:click="$set('status', '{{ $option->value }}')" aria-pressed="{{ $current === $option ? 'true' : 'false' }}"
                    @class(['flex items-center gap-2 rounded-lg px-3 py-1.5', 'bg-accent-700 font-medium text-white' => $current === $option, 'text-stone-600 hover:text-stone-900' => $current !== $option])>
                {{ __("ui.marks.tab.{$option->value}") }}
                <span @class(['text-xs tabular-nums', 'text-accent-100' => $current === $option, 'text-stone-500' => $current !== $option])
                      data-count="{{ $option->value }}">{{ $counts[$option->value] }}</span>
            </button>
        @endforeach
    </div>

    <span wire:loading class="ml-2 text-sm text-stone-400">{{ __('ui.jobs.loading') }}</span>

    @if ($hiddenEmployers !== [])
        <section class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm" aria-labelledby="hidden-employers">
            <h2 id="hidden-employers" class="flex items-center gap-2 text-sm font-semibold"><x-icon name="building" class="text-stone-400" />{{ __('ui.marks.hidden_employers', ['count' => count($hiddenEmployers)]) }}</h2>
            <ul class="mt-2 divide-y divide-stone-100">
                @foreach ($hiddenEmployers as $employer)
                    <li class="flex items-center justify-between gap-3 py-2 text-sm" wire:key="hidden-{{ md5($employer) }}" data-hidden-employer>
                        <span>{{ $employer }}</span>
                        <button type="button" wire:click="showEmployer(@js($employer))" class="shrink-0 text-accent-700 hover:underline">{{ __('ui.marks.show_again') }}</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($postings === [])
        <x-empty-state icon="inbox" class="mt-6" :title="__('ui.marks.empty.'.$current->value)">
            <a href="{{ route('jobs') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-semibold text-accent-700 hover:underline">{{ __('ui.marks.to_jobs') }}<x-icon name="arrow-right" /></a>
        </x-empty-state>
    @else
        <ul class="mt-4 divide-y divide-stone-100 rounded-2xl border border-stone-200 bg-white px-4 shadow-sm">
            @foreach ($postings as $posting)
                @include('livewire.partials.posting', ['posting' => $posting, 'terms' => [], 'showEmployer' => true, 'userMarks' => $userMarks])
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
</div>
