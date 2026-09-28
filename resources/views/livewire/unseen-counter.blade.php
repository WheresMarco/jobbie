<span>
    @if ($count > 0)
        <span class="rounded-full bg-accent-600 px-1.5 text-xs font-medium tabular-nums text-white" data-unseen-count>{{ number_format($count, 0, ',', ' ') }}</span>
    @endif
</span>
