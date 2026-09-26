<div class="space-y-8">
    <section>
        <h1 class="text-xl font-semibold tracking-tight">{{ __('ui.stats.sources') }}</h1>
        @if ($sources === [])
            <p class="mt-3 text-sm text-stone-600">{{ __('ui.stats.none') }}</p>
        @else
            <div class="mt-3 overflow-x-auto rounded-xl border border-stone-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ __('radar.stats.columns.source') }}</th>
                            @foreach ($stages as $stage)
                                <th class="px-3 py-2 text-right font-medium">{{ __("radar.stages.{$stage}") }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-right font-medium">{{ __('radar.stats.columns.tested') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('radar.stats.columns.active_boards') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ __('radar.stats.columns.postings') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 tabular-nums">
                        @foreach ($sources as $source)
                            <tr wire:key="source-{{ $source->source->value }}">
                                <th scope="row" class="px-4 py-2 text-left font-medium">{{ __("radar.sources.{$source->source->value}") }}</th>
                                @foreach ($stages as $stage)
                                    <td class="px-3 py-2 text-right">{{ $source->stages[$stage] ?? 0 }}</td>
                                @endforeach
                                <td class="px-3 py-2 text-right">{{ $source->tested() }}</td>
                                <td class="px-3 py-2 text-right">{{ $source->activeBoards }}</td>
                                <td class="px-4 py-2 text-right">{{ $source->postings }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <h2 class="text-sm font-medium text-stone-500">{{ __('ui.stats.active_postings') }}</h2>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($activePostings, 0, ',', ' ') }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <h2 class="text-sm font-medium text-stone-500">{{ __('ui.stats.by_kind') }}</h2>
            <dl class="mt-2 space-y-1 text-sm">
                @foreach ($byKind as $kind => $count)
                    <div class="flex justify-between"><dt>{{ __("radar.kinds.{$kind}") }}</dt><dd class="tabular-nums">{{ $count }}</dd></div>
                @endforeach
            </dl>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <h2 class="text-sm font-medium text-stone-500">{{ __('ui.stats.by_status') }}</h2>
            <dl class="mt-2 space-y-1 text-sm">
                @foreach ($byStatus as $status => $count)
                    <div class="flex justify-between"><dt>{{ __("radar.statuses.{$status}") }}</dt><dd class="tabular-nums">{{ $count }}</dd></div>
                @endforeach
            </dl>
        </div>
    </section>

    @if ($prefixHits !== [])
        <section>
            <h2 class="text-lg font-semibold tracking-tight">{{ __('ui.stats.prefix_hits') }}</h2>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                @foreach ($prefixHits as $prefix => $hits)
                    <div class="flex justify-between rounded-lg border border-stone-200 bg-white px-3 py-2"><dt>{{ $prefix }}</dt><dd class="tabular-nums">{{ $hits }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endif

    <section>
        <h2 class="text-lg font-semibold tracking-tight">{{ __('ui.stats.txt_without_board') }} <span class="text-stone-500">({{ count($txtWithoutBoard) }})</span></h2>
        @if ($txtWithoutBoard === [])
            <p class="mt-2 text-sm text-stone-600">{{ __('ui.stats.none') }}</p>
        @else
            <ul class="mt-2 columns-2 text-sm sm:columns-4">
                @foreach ($txtWithoutBoard as $domain)
                    <li>{{ $domain }}</li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
