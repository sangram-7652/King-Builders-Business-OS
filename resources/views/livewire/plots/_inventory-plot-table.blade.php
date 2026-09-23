@php
    use App\Support\Plots\PlotRoutes;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Plot> $plots */
@endphp
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-(--border) text-sm">
        <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
            <tr>
                <th class="px-4 py-3">Plot</th>
                <th class="px-4 py-3">Area</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-(--border)">
            @foreach ($plots as $plot)
                @php $showRoute = PlotRoutes::forPlot($plot, 'show'); @endphp
                <tr wire:key="{{ $keyPrefix }}{{ $plot->id }}" class="hover:bg-(--surface-muted)/50">
                    <td class="px-4 py-3">
                        <a href="{{ route($showRoute['name'], $showRoute['params']) }}" wire:navigate
                           class="font-medium text-(--content) hover:text-(--brand-primary)">Plot {{ $plot->plot_number }}</a>
                        @unless ($plot->is_active)
                            <x-ui.badge variant="muted" size="sm" class="ml-1">Archived</x-ui.badge>
                        @endunless
                    </td>
                    <td class="px-4 py-3 text-(--content-muted)">{{ $plot->areaLabel() }}</td>
                    <td class="px-4 py-3"><x-ui.badge :variant="$plot->status->color()">{{ $plot->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.button variant="ghost" size="sm" :href="route($showRoute['name'], $showRoute['params'])" wire:navigate>View</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
