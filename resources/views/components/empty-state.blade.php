@props(['icon', 'title', 'hint' => null])
<div {{ $attributes->merge(['class' => 'rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-12 text-center']) }}>
    <span class="mx-auto grid size-12 place-items-center rounded-full bg-stone-100 text-stone-500">
        <x-icon :name="$icon" class="size-6" />
    </span>
    <p class="mt-4 font-semibold">{{ $title }}</p>
    @if ($hint)
        <p class="mx-auto mt-1 max-w-md text-sm text-stone-600">{{ $hint }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
