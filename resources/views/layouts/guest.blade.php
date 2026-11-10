<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased">
        <div class="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            {{-- Brand panel --}}
            <aside class="relative hidden overflow-hidden bg-gradient-to-br from-accent-700 via-accent-800 to-accent-950 p-12 text-white lg:flex lg:flex-col">
                {{-- Radar rings, centred on the logo's ping. --}}
                <svg class="pointer-events-none absolute -right-48 -top-48 size-[44rem] text-white/10" viewBox="0 0 200 200" fill="none" stroke="currentColor" aria-hidden="true">
                    <circle cx="100" cy="100" r="20"/>
                    <circle cx="100" cy="100" r="40"/>
                    <circle cx="100" cy="100" r="60"/>
                    <circle cx="100" cy="100" r="80"/>
                    <circle cx="100" cy="100" r="99"/>
                </svg>
                <div class="pointer-events-none absolute -bottom-32 -left-32 size-96 rounded-full bg-accent-400/20 blur-3xl" aria-hidden="true"></div>

                <a href="{{ url('/') }}" class="relative self-start" aria-label="{{ __('ui.app_name') }}"><x-logo light /></a>

                <div class="relative my-auto max-w-md py-12">
                    <p class="text-sm font-semibold uppercase tracking-widest text-accent-200">{{ __('ui.brand.eyebrow') }}</p>
                    <h2 class="mt-3 text-4xl font-extrabold leading-tight tracking-tight xl:text-5xl">{{ __('ui.brand.headline') }}</h2>
                    <p class="mt-4 text-lg leading-relaxed text-accent-100">{{ __('ui.brand.pitch') }}</p>

                    <ul class="mt-10 space-y-5">
                        @foreach (['radar', 'bookmark', 'sparkles'] as $i => $icon)
                            @continue($icon === 'sparkles' && ! \App\Livewire\ResumeMatches::enabled())
                            <li class="flex gap-4">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-white/10 ring-1 ring-white/15">
                                    <x-icon :name="$icon" class="size-5" />
                                </span>
                                <div>
                                    <p class="font-semibold">{{ __("ui.brand.features.{$i}.title") }}</p>
                                    <p class="text-sm text-accent-100/90">{{ __("ui.brand.features.{$i}.text") }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="relative text-sm text-accent-200">
                    {{ __('ui.brand.credit') }}
                    <a href="https://wheresmar.co" target="_blank" rel="noopener" class="font-semibold text-white hover:underline">Marco Sommer</a>
                </p>
            </aside>

            {{-- Form --}}
            <main class="flex flex-col px-4 py-8 sm:px-8">
                <a href="{{ url('/') }}" class="self-start lg:hidden" aria-label="{{ __('ui.app_name') }}">
                    <x-logo />
                </a>
                <div class="my-auto w-full py-10">
                    <div class="mx-auto w-full max-w-sm">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
