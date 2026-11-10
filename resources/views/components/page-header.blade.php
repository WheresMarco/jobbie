@props(['icon', 'title', 'subtitle' => null])
<div {{ $attributes->merge(['class' => 'flex items-start gap-4']) }}>
    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-accent-50 text-accent-700 ring-1 ring-accent-100">
        <x-icon :name="$icon" class="size-5" />
    </span>
    <div class="min-w-0">
        <h1 class="text-2xl font-bold tracking-tight">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 max-w-3xl text-sm text-stone-600">{{ $subtitle }}</p>
        @endif
    </div>
</div>
