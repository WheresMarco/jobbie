@props(['wordmark' => true, 'light' => false])
@php($gradient = 'jobbie-logo-'.uniqid())
{{-- The mark matches public/icon.svg: a "j" whose dot sends out a ping. --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    <svg class="size-8 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">
        <defs>
            <linearGradient id="{{ $gradient }}" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#27b892"/>
                <stop offset="1" stop-color="#006e51"/>
            </linearGradient>
        </defs>
        <rect width="32" height="32" rx="8" fill="url(#{{ $gradient }})"/>
        <g fill="none" stroke="#fff" stroke-linecap="round">
            <path d="M16 13.5v8.75A4.75 4.75 0 0 1 11.25 27H10" stroke-width="3.25"/>
            <path d="M19.5 5a4.75 4.75 0 0 1 0 6.75" stroke-width="1.75" opacity=".85"/>
            <path d="M22.25 2.75a8.25 8.25 0 0 1 0 11.25" stroke-width="1.75" opacity=".5"/>
        </g>
        <circle cx="16" cy="8.4" r="2.1" fill="#fff"/>
    </svg>
    @if ($wordmark)
        <span @class(['text-xl font-extrabold tracking-tight', 'text-white' => $light, 'text-stone-900' => ! $light])>{{ __('ui.app_name') }}</span>
    @else
        <span class="sr-only">{{ __('ui.app_name') }}</span>
    @endif
</span>
