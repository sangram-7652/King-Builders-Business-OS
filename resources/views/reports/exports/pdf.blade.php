@php
    /** @var \App\Support\Reports\ReportExportPayload $payload */
    /** @var array{name:string,primary:string} $brand */
    use App\Support\Reports\ReportColumn;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #1f2937; margin: 0; }
        .header { border-bottom: 2px solid {{ $brand['primary'] }}; padding-bottom: 8px; margin-bottom: 12px; }
        .brand { font-size: 15px; font-weight: bold; color: {{ $brand['primary'] }}; }
        .title { font-size: 13px; font-weight: bold; margin-top: 2px; }
        .meta { color: #6b7280; font-size: 9px; margin-top: 3px; }
        .filters { margin: 8px 0 14px; }
        .filters td { padding: 1px 10px 1px 0; font-size: 9px; }
        .filters .k { color: #6b7280; }
        h2 { font-size: 11px; margin: 16px 0 4px; color: #111827; page-break-after: avoid; }
        .note { font-size: 8px; color: #6b7280; margin-bottom: 3px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.data th, table.data td { border: 0.5px solid #d1d5db; padding: 3px 5px; }
        table.data th { background: #f3f4f6; text-align: left; font-size: 9px; }
        table.data td.r, table.data th.r { text-align: right; }
        table.data tr.totals td { font-weight: bold; background: #f9fafb; }
        .empty { color: #6b7280; font-style: italic; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">{{ $brand['name'] }}</div>
        <div class="title">{{ $payload->title }}</div>
        <div class="meta">
            {{ $payload->periodLabel }} &nbsp;·&nbsp; Generated {{ $payload->generatedAtLabel() }} by {{ $payload->generatedBy }}
        </div>
    </div>

    <table class="filters">
        @foreach ($payload->filterSummary as $label => $value)
            <tr><td class="k">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    @foreach ($payload->tables as $table)
        <h2>{{ $table->title }}</h2>
        @if ($table->note)<div class="note">{{ $table->note }}</div>@endif

        @if ($table->isEmpty())
            <p class="empty">No data for the selected filters.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($table->columns as $col)
                            <th class="{{ $col->align === 'right' ? 'r' : '' }}">{{ $col->label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($table->rows as $row)
                        <tr>
                            @foreach ($table->columns as $col)
                                <td class="{{ $col->align === 'right' ? 'r' : '' }}">{{ $col->display($row[$col->key] ?? null) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    @if ($table->totals)
                        <tr class="totals">
                            @foreach ($table->columns as $col)
                                <td class="{{ $col->align === 'right' ? 'r' : '' }}">{{ $col->display($table->totals[$col->key] ?? null) }}</td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
