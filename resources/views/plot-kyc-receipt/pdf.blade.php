<?php
/** @var \App\Models\Booking $booking */
/** @var \App\Support\Branding $brand */
/** @var array<string, mixed> $data */
$primary = $brand->colors['primary'];
$money = fn ($v) => $v === null ? '—' : '₹'.number_format((float) $v, 2);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #1a1a1a; font-size: 10.5px; }
        .wrap { padding: 16px 32px; }

        .header-center { text-align: center; }
        .header-center .t1 { font-size: 17px; font-weight: bold; letter-spacing: 1px; }
        .header-center .t2 { font-size: 12px; font-weight: bold; color: #444; letter-spacing: 2px; margin-top: 1px; }
        .meta { font-size: 9.5px; color: #444; margin-top: 2px; }

        .rule { border-bottom: 2px solid {{ $primary }}; margin: 6px 0 10px; }

        .section-title { font-size: 10.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px;
            color: #fff; background: {{ $primary }}; padding: 3px 6px; margin: 10px 0 5px; }

        table.grid { width: 100%; border-collapse: collapse; }
        table.grid td { padding: 2px 6px 2px 0; font-size: 10px; vertical-align: top; line-height: 1.25; border-bottom: 1px solid #eee; }
        table.grid td.lbl { color: #444; width: 22%; white-space: nowrap; padding-top: 3px; padding-bottom: 3px; }
        table.grid td.val { width: 28%; font-weight: bold; color: #111; padding-top: 3px; padding-bottom: 3px; }

        table.list { width: 100%; border-collapse: collapse; margin-top: 2px; }
        table.list th { background: #f3f4f6; text-align: left; font-size: 9px; text-transform: uppercase; padding: 4px 6px; border: 1px solid #e5e7eb; }
        table.list td { font-size: 10px; padding: 4px 6px; border: 1px solid #e5e7eb; }

        .party-block { margin-bottom: 6px; }
        .party-block:last-child { margin-bottom: 0; }

        .not-tracked { color: #888; font-style: italic; font-weight: normal; }

        /* Plot Details + compass, side by side — the compass never overlaps
           or reflows the Plot Details grid, it just sits in the spare column. */
        table.plot-with-compass { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.plot-with-compass td.plot-details-col { width: 72%; vertical-align: top; padding: 0; }
        table.plot-with-compass td.compass-col { width: 28%; vertical-align: middle; text-align: center; padding: 0; }

        /* A simple N/E/S/W cross built from table-cell borders — reliable in
           dompdf, unlike border-radius circles or CSS transforms. */
        table.compass { border-collapse: collapse; margin: 4px auto 0; }
        table.compass td { width: 20px; height: 18px; text-align: center; vertical-align: middle;
            font-size: 8px; font-weight: bold; color: #444; padding: 0; }
        table.compass td.c-mid { border-left: 1px solid #999; border-right: 1px solid #999; }
        table.compass tr.c-row-mid td { border-top: 1px solid #999; border-bottom: 1px solid #999; }
    </style>
</head>
<body>
<div class="wrap">

    {{-- HEADER --}}
    <div class="header-center">
        <div class="t1">{{ $data['documentTitle'] }}</div>
        <div class="t2">{{ $data['documentSubtitle'] }}</div>
        <div class="meta">Booking: {{ $booking->booking_number }}</div>
        <div class="meta">Date: {{ now()->format('d/m/Y') }}</div>
    </div>
    <div class="rule"></div>

    {{-- VILLAGE / GATA / VIKRAY MULY --}}
    <div class="section-title">Village / Gata / Vikray Muly</div>
    <table class="grid">
        <tr>
            <td class="lbl">Village Name</td><td class="val">{{ $data['village']['villageName'] ?: '—' }}</td>
            <td class="lbl">Gata No.</td><td class="val">{{ $data['village']['gataNumber'] ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Vikray Muly</td><td class="val" colspan="3">{{ $money($data['village']['vikrayMulyAmount']) }}</td>
        </tr>
    </table>

    {{-- PLOT DETAILS --}}
    <div class="section-title">Plot Details</div>
    <table class="plot-with-compass">
        <tr>
            <td class="plot-details-col">
                <table class="grid">
                    <tr>
                        <td class="lbl">Plot No.</td><td class="val">{{ $data['plot']['plotNumber'] ?: '—' }}</td>
                        <td class="lbl">Site Name</td><td class="val">{{ $data['plot']['siteName'] ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Plot Area</td><td class="val">{{ $data['plot']['area'] ?: '—' }}</td>
                        <td class="lbl"></td><td class="val"></td>
                    </tr>
                    <tr>
                        <td class="lbl">Front</td><td class="val">{{ $data['plot']['front'] ?: '—' }}</td>
                        <td class="lbl">Depth</td><td class="val">{{ $data['plot']['depth'] ?: '—' }}</td>
                    </tr>
                </table>
            </td>
            <td class="compass-col">
                <table class="compass">
                    <tr>
                        <td></td>
                        <td class="c-mid">N</td>
                        <td></td>
                    </tr>
                    <tr class="c-row-mid">
                        <td>W</td>
                        <td class="c-mid"></td>
                        <td>E</td>
                    </tr>
                    <tr>
                        <td></td>
                        <td class="c-mid">S</td>
                        <td></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- PLOT CHAUHADDI --}}
    <div class="section-title">Plot Chauhaddi</div>
    <table class="grid">
        <tr>
            <td class="lbl">East</td><td class="val">{{ $data['chauhaddi']['east'] ?: '—' }}</td>
            <td class="lbl">West</td><td class="val">{{ $data['chauhaddi']['west'] ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">North</td><td class="val">{{ $data['chauhaddi']['north'] ?: '—' }}</td>
            <td class="lbl">South</td><td class="val">{{ $data['chauhaddi']['south'] ?: '—' }}</td>
        </tr>
    </table>

    {{-- SELLER / COMPANY --}}
    <div class="section-title">Seller / Company</div>
    <table class="grid">
        <tr>
            <td class="lbl">Company Name</td><td class="val">{{ $data['seller']['companyName'] ?: '—' }}</td>
            <td class="lbl">Director Name</td><td class="val">{{ $data['seller']['directorName'] ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Address</td><td class="val" colspan="3">{{ $data['seller']['address'] ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">PAN</td><td class="val">{{ $data['seller']['pan'] ?: '—' }}</td>
            <td class="lbl">Mobile</td><td class="val">{{ $data['seller']['mobile'] ?: '—' }}</td>
        </tr>
    </table>

    {{-- BUYER / CUSTOMER --}}
    <div class="section-title">Buyer / Customer</div>
    @forelse ($data['buyers'] as $buyer)
        <div class="party-block">
            <table class="grid">
                <tr>
                    <td class="lbl">Name</td><td class="val">{{ $buyer['name'] ?: '—' }}{{ $buyer['isPrimary'] ? ' (Primary)' : '' }}</td>
                    <td class="lbl">Mobile</td><td class="val">{{ $buyer['mobile'] ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="lbl">Address</td><td class="val">{{ $buyer['address'] ?: '—' }}</td>
                    <td class="lbl">PAN</td><td class="val">{{ $buyer['pan'] ?: '—' }}</td>
                </tr>
            </table>
        </div>
    @empty
        <p>No buyer recorded.</p>
    @endforelse

    {{-- WITNESS --}}
    <div class="section-title">Witness</div>
    <table class="list">
        <thead>
            <tr><th>#</th><th>Name</th><th>Address</th><th>Mobile</th></tr>
        </thead>
        <tbody>
            @foreach ($data['witnesses'] as $i => $witness)
                <tr>
                    <td>Witness {{ $i + 1 }}</td>
                    <td>{{ $witness['name'] ?: '—' }}</td>
                    <td>{{ $witness['address'] ?: '—' }}</td>
                    <td>{{ $witness['mobile'] ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- PAYMENT DETAILS --}}
    <div class="section-title">Payment Details</div>
    @if (count($data['payments']) > 0)
        <table class="list">
            <thead>
                <tr><th>Reference No.</th><th>Amount</th><th>Bank Name</th><th>Date</th></tr>
            </thead>
            <tbody>
                @foreach ($data['payments'] as $payment)
                    <tr>
                        <td>{{ $payment['referenceNumber'] ?: '—' }}</td>
                        <td>{{ $money($payment['amount']) }}</td>
                        <td>{{ $payment['bankName'] ?: '—' }}</td>
                        <td>{{ $payment['date']?->format('d/m/Y') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>No successful payments recorded yet.</p>
    @endif

    {{-- FINANCIAL SUMMARY --}}
    <div class="section-title">Financial Summary</div>
    <table class="grid">
        <tr>
            <td class="lbl">Total Plot Amount</td><td class="val">{{ $money($data['financial']['totalPlotAmount']) }}</td>
            <td class="lbl">Total PLC Amount</td><td class="val">{{ $money($data['financial']['totalPlcAmount']) }}</td>
        </tr>
        <tr>
            <td class="lbl">Paid Plot Amount</td><td class="val">{{ $money($data['financial']['paidPlotAmount']) }}</td>
            <td class="lbl">Paid PLC Amount</td>
            <td class="val">
                @if ($data['financial']['paidPlcAmount'] === null)
                    <span class="not-tracked">Not separately tracked</span>
                @else
                    {{ $money($data['financial']['paidPlcAmount']) }}
                @endif
            </td>
        </tr>
        <tr>
            <td class="lbl">Balance Amount</td><td class="val">{{ $money($data['financial']['balanceAmount']) }}</td>
            <td class="lbl">Balance PLC Amount</td>
            <td class="val">
                @if ($data['financial']['balancePlcAmount'] === null)
                    <span class="not-tracked">Not separately tracked</span>
                @else
                    {{ $money($data['financial']['balancePlcAmount']) }}
                @endif
            </td>
        </tr>
    </table>
</div>
</body>
</html>
