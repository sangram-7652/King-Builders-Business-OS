<?php
/** @var \App\Models\Booking $booking */
/** @var \App\Support\Branding $brand */
/** @var array<string, mixed> $data */
$primary = $brand->colors['primary'];
$money = fn ($v) => $v === null ? '—' : '₹'.number_format((float) $v, 2);
$logoFile = $brand->logoPath ? public_path($brand->logoPath) : null;
$signatureFile = $brand->signaturePath ? public_path($brand->signaturePath) : null;
$stampFile = $brand->stampPath ? public_path($brand->stampPath) : null;
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

        table.head { width: 100%; border-collapse: collapse; }
        table.head td { vertical-align: top; }
        .logo { height: 52px; margin-bottom: 2px; }
        .company-name { font-size: 15px; font-weight: bold; color: #111; letter-spacing: .3px; }
        .doc-title { text-align: right; }
        .doc-title .t1 { font-size: 16px; font-weight: bold; letter-spacing: 1px; }
        .doc-title .t2 { font-size: 11px; font-weight: bold; color: #444; letter-spacing: 2px; }
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

        .sign-block { page-break-inside: avoid; margin-top: 16px; }
        table.signatures { width: 100%; border-collapse: collapse; }
        table.signatures td { width: 33.33%; text-align: center; padding-top: 30px; font-size: 9.5px; }
        table.signatures td .line { border-top: 1px solid #111; padding-top: 4px; display: inline-block; min-width: 80%; }
        .sign .stamp-img { height: 18px; width: auto; }
        .sign .signature-img { height: 30px; width: auto; margin-top: -8px; margin-bottom: -6px; }

        .footer { margin-top: 10px; border-top: 2px solid {{ $primary }}; padding-top: 6px; font-size: 8.5px; color: #333; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">

    {{-- HEADER --}}
    <table class="head">
        <tr>
            <td style="width: 55%;">
                @if ($logoFile && file_exists($logoFile))
                    <img class="logo" src="{{ $logoFile }}"><br>
                @endif
                <div class="company-name">{{ strtoupper($brand->name) }}</div>
            </td>
            <td style="width: 45%;" class="doc-title">
                <div class="t1">{{ $data['documentTitle'] }}</div>
                <div class="t2">{{ $data['documentSubtitle'] }}</div>
                <div class="meta">Booking: {{ $booking->booking_number }}</div>
                <div class="meta">Date: {{ now()->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>
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

    <div class="sign-block">
        <table class="signatures">
            <tr>
                <td>
                    <span class="line">Buyer / Customer</span>
                </td>
                <td>
                    <span class="line">Witness 1 &amp; 2</span>
                </td>
                <td class="sign">
                    @if ($stampFile && file_exists($stampFile))
                        <img class="stamp-img" src="{{ $stampFile }}"><br>
                    @endif
                    @if ($signatureFile && file_exists($signatureFile))
                        <img class="signature-img" src="{{ $signatureFile }}"><br>
                    @endif
                    <span class="line">Seller / Company</span>
                </td>
            </tr>
        </table>

        <div class="footer">
            This is a system-generated Plot KYC / Registry KYC Receipt for {{ $booking->booking_number }}.
            @if ($brand->contact['head_office_address'])
                Head Office:- {{ $brand->contact['head_office_address'] }}
            @endif
        </div>
    </div>
</div>
</body>
</html>
