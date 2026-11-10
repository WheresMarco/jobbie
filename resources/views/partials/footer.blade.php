<footer class="border-t border-stone-200 bg-white">
    <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-6 text-xs text-stone-500 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <span class="flex items-center gap-2">
            <x-logo :wordmark="false" class="[&>svg]:size-5" />
            <span><span class="font-semibold text-stone-700">{{ __('ui.app_name') }}</span> · {{ __('ui.brand.tagline') }}</span>
        </span>
        <span class="flex flex-wrap items-center gap-x-2">
            <span>{{ __('ui.brand.footer') }}</span>
            <span aria-hidden="true">·</span>
            <span data-credit>
                {{ __('ui.brand.credit') }}
                <a href="https://wheresmar.co" target="_blank" rel="noopener" class="font-semibold text-stone-700 hover:text-accent-700 hover:underline">Marco Sommer</a>
            </span>
        </span>
    </div>
</footer>
