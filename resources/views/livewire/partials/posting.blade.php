@php
    /** @var \App\Models\JobPosting $posting */
    $remoteKey = match ($posting->remote?->value) {
        'fully' => 'fully',
        'hybrid' => 'hybrid',
        'onsite', 'none' => 'onsite',
        default => null,
    };
@endphp
<li class="py-3" wire:key="posting-{{ $posting->id }}">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <a href="{{ $posting->url }}" target="_blank" rel="noopener noreferrer"
           class="font-medium text-stone-900 hover:text-accent-700 hover:underline">{{ \App\Search\Highlighter::highlight($posting->title, $terms) }}</a>
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
        @if ($posting->first_seen_at->greaterThanOrEqualTo(now()->subDays(config()->integer('radar.new_job_days'))))
            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{{ __('ui.jobs.new_badge') }}</span>
        @endif
    </div>
    @if ($terms !== [] && $posting->description)
        <p class="mt-1.5 text-sm leading-relaxed text-stone-600">{{ \App\Search\Highlighter::snippet($posting->description, $terms) }}</p>
    @endif
</li>
