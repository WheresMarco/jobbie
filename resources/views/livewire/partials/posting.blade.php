@php
    /** @var \App\Models\JobPosting $posting */
    $remoteKey = match ($posting->remote?->value) {
        'fully' => 'fully',
        'hybrid' => 'hybrid',
        'onsite', 'none' => 'onsite',
        default => null,
    };
    /** @var \App\Tracking\UserMarks|null $userMarks */
    $userMarks ??= null;
    $status = $userMarks?->status($posting->id);
    $isUnseen = $userMarks?->isUnseen($posting->id, $posting->first_seen_at) ?? false;
@endphp
<li class="py-3" wire:key="posting-{{ $posting->id }}" @if ($status) data-status="{{ $status->value }}" @endif>
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <span class="flex items-baseline gap-2">
            @if ($isUnseen)
                <span class="inline-block size-2 shrink-0 rounded-full bg-accent-600" title="{{ __('ui.marks.unseen') }}" data-unseen></span>
                <span class="sr-only">{{ __('ui.marks.unseen') }}</span>
            @endif
            <a href="{{ $posting->url }}" target="_blank" rel="noopener noreferrer"
               @if ($isUnseen) wire:click="markSeen({{ $posting->id }})" @endif
               class="font-medium text-stone-900 hover:text-accent-700 hover:underline">{{ \App\Search\Highlighter::highlight($posting->title, $terms) }}</a>
        </span>
        @if ($posting->posted_at)
            <time datetime="{{ $posting->posted_at->toIso8601String() }}" class="shrink-0 text-xs text-stone-500">
                {{ $posting->posted_at->locale(app()->getLocale())->isoFormat('D MMM YYYY') }}
            </time>
        @endif
    </div>
    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-stone-600">
        @if ($showEmployer ?? false)
            <span class="font-medium text-stone-700">{{ \App\Search\Highlighter::highlight((string) ($posting->company ?? $posting->board?->name ?? ''), $terms) }}</span>
        @endif
        @if ($posting->cities !== [])
            <span>{{ implode(', ', $posting->cities) }}</span>
        @endif
        @if ($remoteKey)
            <span @class([
                'rounded-full px-2 py-0.5 text-xs font-medium',
                'bg-accent-100 text-accent-700' => $remoteKey === 'fully',
                'bg-sky-100 text-sky-800' => $remoteKey === 'hybrid',
                'bg-stone-100 text-stone-700' => $remoteKey === 'onsite',
            ])>{{ __("ui.remote.{$remoteKey}") }}</span>
        @endif
        @if ($posting->department)
            <span class="text-stone-500">{{ $posting->department }}</span>
        @endif
        @if ($posting->in_platsbanken)
            <span class="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800">{{ __('ui.jobs.in_platsbanken') }}</span>
        @endif
        @if ($posting->first_seen_at->greaterThanOrEqualTo(now()->subDays(config()->integer('radar.new_job_days'))))
            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{{ __('ui.jobs.new_badge') }}</span>
        @endif
        @unless ($posting->active)
            <span class="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-700">{{ __('ui.jobs.closed_badge') }}</span>
        @endunless
    </div>
    @if ($terms !== [] && $posting->description)
        <p class="mt-1.5 text-sm leading-relaxed text-stone-600">{{ \App\Search\Highlighter::snippet($posting->description, $terms) }}</p>
    @endif
    @if ($userMarks)
        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs" role="group" aria-label="{{ __('ui.marks.label') }}">
            @foreach (\App\Enums\MarkStatus::cases() as $option)
                {{-- Clicking the current status clears it. --}}
                <button type="button" wire:click="mark({{ $posting->id }}, '{{ $status === $option ? '' : $option->value }}')"
                        aria-pressed="{{ $status === $option ? 'true' : 'false' }}"
                        @class([
                            'rounded-md border px-2 py-1 font-medium',
                            'border-stone-300 bg-white text-stone-700 hover:bg-stone-100' => $status !== $option,
                            'border-accent-600 bg-accent-600 text-white' => $status === $option && $option !== \App\Enums\MarkStatus::Rejected,
                            'border-stone-700 bg-stone-700 text-white' => $status === $option && $option === \App\Enums\MarkStatus::Rejected,
                        ])>{{ __($status === $option ? "ui.marks.status.{$option->value}" : "ui.marks.action.{$option->value}") }}</button>
            @endforeach
            @if ($isUnseen)
                <button type="button" wire:click="markSeen({{ $posting->id }})" class="px-2 py-1 text-stone-500 hover:text-stone-900 hover:underline">{{ __('ui.marks.mark_seen') }}</button>
            @endif
        </div>
    @endif
</li>
