@props([
    'report',   // report key: sales | inventory | collections | mis
    'filters',  // App\Support\Reports\ReportFilterData
    'extras' => null, // optional extra filter DTO exposing toQueryString()
])

@php
    use App\Enums\ExportFormat;

    $exportable = ['sales', 'inventory', 'collections', 'mis'];
    $query = $filters->toQueryString() + ($extras?->toQueryString() ?? []);
@endphp

@if (in_array($report, $exportable, true))
    <div class="flex items-center gap-1" x-data="{ open: false }">
        <span class="text-xs font-medium text-(--content-muted)">Export</span>
        @foreach (ExportFormat::cases() as $format)
            <a href="{{ route('reports.export', ['type' => $report, 'format' => $format->value] + $query) }}"
               @if ($format === ExportFormat::Print) target="_blank" rel="noopener" @endif
               class="rounded-md border border-(--border) bg-(--surface) px-2 py-1 text-xs font-medium text-(--content) transition hover:bg-(--surface-muted)">
                {{ $format->label() }}
            </a>
        @endforeach
    </div>
@endif
