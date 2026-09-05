@props([
    'meta',        // ['title','description','milestone', ...]
    'filters',     // App\Support\Reports\ReportFilterData
])

<x-ui.card>
    <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
        <span class="flex size-12 items-center justify-center rounded-full bg-(--brand-primary)/10 text-(--brand-primary)">
            <x-app.icon name="layers" class="size-6" />
        </span>
        <p class="mt-4 text-sm font-semibold text-(--content)">Analytics arrive in {{ $meta['milestone'] }}</p>
        <p class="mt-1 max-w-md text-xs text-(--content-muted)">{{ $meta['description'] }}</p>

        <dl class="mt-6 grid w-full max-w-lg grid-cols-2 gap-3 text-left sm:grid-cols-3">
            <div class="rounded-lg border border-(--border) px-3 py-2">
                <dt class="text-[11px] uppercase tracking-wider text-(--content-muted)">Period</dt>
                <dd class="mt-0.5 text-sm text-(--content)">{{ $filters->periodLabel() }}</dd>
            </div>
            <div class="rounded-lg border border-(--border) px-3 py-2">
                <dt class="text-[11px] uppercase tracking-wider text-(--content-muted)">From</dt>
                <dd class="mt-0.5 text-sm text-(--content)">{{ $filters->from->format('d M Y') }}</dd>
            </div>
            <div class="rounded-lg border border-(--border) px-3 py-2">
                <dt class="text-[11px] uppercase tracking-wider text-(--content-muted)">To</dt>
                <dd class="mt-0.5 text-sm text-(--content)">{{ $filters->to->format('d M Y') }}</dd>
            </div>
            <div class="rounded-lg border border-(--border) px-3 py-2">
                <dt class="text-[11px] uppercase tracking-wider text-(--content-muted)">Project</dt>
                <dd class="mt-0.5 text-sm text-(--content)">{{ $filters->projectId ? '#'.$filters->projectId : 'All' }}</dd>
            </div>
            <div class="rounded-lg border border-(--border) px-3 py-2">
                <dt class="text-[11px] uppercase tracking-wider text-(--content-muted)">Block</dt>
                <dd class="mt-0.5 text-sm text-(--content)">{{ $filters->blockId ? '#'.$filters->blockId : 'All' }}</dd>
            </div>
            <div class="rounded-lg border border-(--border) px-3 py-2">
                <dt class="text-[11px] uppercase tracking-wider text-(--content-muted)">Salesperson</dt>
                <dd class="mt-0.5 text-sm text-(--content)">{{ $filters->salespersonId ? '#'.$filters->salespersonId : 'All' }}</dd>
            </div>
        </dl>
    </div>
</x-ui.card>
