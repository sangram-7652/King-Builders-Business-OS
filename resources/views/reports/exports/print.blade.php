@php
    /** @var \App\Support\Reports\ReportExportPayload $payload */
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $payload->title }} — {{ $payload->periodLabel }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px;
            font: 13px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #1f2937; background: #fff;
        }
        header { border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 16px; }
        h1 { font-size: 18px; margin: 0; }
        .meta { color: #6b7280; font-size: 12px; margin-top: 4px; }
        .filters { display: flex; flex-wrap: wrap; gap: 4px 20px; margin: 10px 0 20px; font-size: 12px; }
        .filters span { color: #6b7280; }
        h2 { font-size: 14px; margin: 22px 0 6px; }
        .note { font-size: 11px; color: #6b7280; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 5px 8px; }
        th { background: #f3f4f6; text-align: left; }
        td.r, th.r { text-align: right; font-variant-numeric: tabular-nums; }
        tr.totals td { font-weight: 700; background: #f9fafb; }
        .empty { color: #6b7280; font-style: italic; }
        .toolbar { position: fixed; top: 12px; right: 12px; }
        .toolbar button { font: inherit; padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; cursor: pointer; }
        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            h2 { page-break-after: avoid; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; }
            thead { display: table-header-group; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Print</button></div>

    <header>
        <h1>{{ $payload->title }}</h1>
        <div class="meta">
            {{ $payload->periodLabel }} · Generated {{ $payload->generatedAtLabel() }} by {{ $payload->generatedBy }}
        </div>
    </header>

    <div class="filters">
        @foreach ($payload->filterSummary as $label => $value)
            <div><span>{{ $label }}:</span> {{ $value }}</div>
        @endforeach
    </div>

    @foreach ($payload->tables as $table)
        <h2>{{ $table->title }}</h2>
        @if ($table->note)<div class="note">{{ $table->note }}</div>@endif

        @if ($table->isEmpty())
            <p class="empty">No data for the selected filters.</p>
        @else
            <table>
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

    <script>
        // Auto-open the print dialog once, but let the user cancel and read on screen.
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
