@php /** @var \App\Support\Plots\PlotStatusCounts $counts */ @endphp
<div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
    <div class="rounded-xl border border-(--border) bg-(--surface) p-4 shadow-sm">
        <p class="text-xs font-medium uppercase tracking-wider text-(--content-muted)">Total</p>
        <p class="mt-1 text-2xl font-semibold text-(--content)">{{ $counts->total }}</p>
    </div>
    @foreach ($counts->rows() as $row)
        <div class="rounded-xl border border-(--border) bg-(--surface) p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wider text-(--content-muted)">{{ $row['status']->label() }}</p>
            <p class="mt-1 text-2xl font-semibold text-(--content)">{{ $row['count'] }}</p>
        </div>
    @endforeach
</div>
