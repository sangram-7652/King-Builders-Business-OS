<div class="space-y-6">
    <x-ui.page-header title="Master Data" description="Configuration data that feeds the Projects, Plots, Booking, Payments and Registry modules." />

    @foreach ($groups as $section)
        <div>
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-(--content-muted)">
                {{ $section['group']->label() }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($section['items'] as $item)
                    @php $resource = $item['resource']; @endphp
                    <a href="{{ route('masters.index', ['resource' => $resource->slug()]) }}" wire:navigate
                       class="group rounded-xl border border-(--border) bg-(--surface) p-4 transition hover:border-(--brand-primary) hover:shadow-sm">
                        <div class="flex items-start justify-between">
                            <p class="font-medium text-(--content) group-hover:text-(--brand-primary)">
                                {{ $resource->pluralLabel() }}
                            </p>
                            <x-app.icon name="chevron-down" class="size-4 -rotate-90 text-(--content-muted)" />
                        </div>
                        <p class="mt-1 text-xs text-(--content-muted)">
                            {{ $item['active'] }} active&nbsp;·&nbsp;{{ $item['total'] }} total
                        </p>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
