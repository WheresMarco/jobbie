@props(['user', 'size' => 32])
{{-- The Gravatar over the initials; it removes itself when the user has none. --}}
<span {{ $attributes->merge(['class' => 'relative grid shrink-0 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-accent-400 to-accent-700 font-bold text-white']) }}
      style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ round($size * 0.38) }}px">
    <span data-initials>{{ $user->initials() }}</span>
    <img src="{{ $user->gravatarUrl($size * 2) }}" alt="" width="{{ $size }}" height="{{ $size }}" loading="lazy" referrerpolicy="no-referrer"
         class="absolute inset-0 size-full object-cover" onerror="this.remove()" data-gravatar>
</span>
