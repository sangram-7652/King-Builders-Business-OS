@php
    /** @var \App\Models\Receipt $receipt */
    $payment = $receipt->payment;
    $booking = $receipt->booking;
    $primary = $brand['primary'] ?? '#2563eb';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #1f2933; font-size: 12px; }
        .wrap { padding: 32px 40px; }
        .head { border-bottom: 3px solid {{ $primary }}; padding-bottom: 12px; margin-bottom: 20px; }
        .company { font-size: 20px; font-weight: bold; color: {{ $primary }}; }
        .doc-title { float: right; text-align: right; }
        .doc-title h1 { margin: 0; font-size: 18px; letter-spacing: 1px; }
        .doc-title .num { color: #52606d; font-size: 13px; }
        table.kv { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.kv td { padding: 5px 8px; vertical-align: top; }
        table.kv td.label { color: #7b8794; width: 32%; }
        .amount-box { border: 2px solid {{ $primary }}; border-radius: 6px; padding: 14px 18px; margin: 16px 0; }
        .amount-box .lbl { color: #7b8794; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
        .amount-box .val { font-size: 24px; font-weight: bold; color: {{ $primary }}; }
        .void { color: #b91c1c; font-weight: bold; border: 2px dashed #b91c1c; padding: 6px 12px; display: inline-block; margin-bottom: 12px; }
        .foot { margin-top: 40px; border-top: 1px solid #cbd2d9; padding-top: 12px; color: #7b8794; font-size: 11px; }
        .sign { margin-top: 48px; }
        .sign .line { border-top: 1px solid #1f2933; width: 200px; padding-top: 4px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="doc-title">
            <h1>PAYMENT RECEIPT</h1>
            <div class="num">{{ $receipt->receipt_number }}</div>
        </div>
        <div class="company">{{ $brand['name'] }}</div>
        <div style="color:#7b8794">{{ $brand['name'] }} Business OS</div>
    </div>

    @if ($receipt->isVoided())
        <div class="void">VOID — {{ $receipt->void_reason }}</div>
    @endif

    <table class="kv">
        <tr><td class="label">Receipt no.</td><td>{{ $receipt->receipt_number }}</td>
            <td class="label">Date</td><td>{{ $receipt->payment_date?->format('d M Y') }}</td></tr>
        <tr><td class="label">Project</td><td>{{ $booking->project?->name ?? '—' }}</td>
            <td class="label">Booking</td><td>{{ $booking->booking_number }}</td></tr>
        <tr><td class="label">Plot</td><td>{{ $booking->plot?->plot_number ?? '—' }}</td>
            <td class="label">Payment no.</td><td>{{ $payment->payment_number }}</td></tr>
        <tr><td class="label">Received from</td><td>{{ $receipt->buyer_name_snapshot }}</td>
            <td class="label">Payment mode</td><td>{{ $receipt->payment_mode_label }}</td></tr>
        <tr><td class="label">Reference</td><td>{{ $receipt->reference_number ?: '—' }}</td>
            <td class="label">Status</td><td>{{ $payment->status->label() }}</td></tr>
    </table>

    <div class="amount-box">
        <div class="lbl">Amount received</div>
        <div class="val">&#8377; {{ number_format((float) $receipt->amount, 2) }}</div>
    </div>

    @if ($payment->cheque_number)
        <table class="kv">
            <tr><td class="label">Cheque no.</td><td>{{ $payment->cheque_number }}</td>
                <td class="label">Cheque bank</td><td>{{ $payment->cheque_bank_name ?: '—' }}</td></tr>
            <tr><td class="label">Cheque date</td><td>{{ $payment->cheque_date?->format('d M Y') }}</td>
                <td class="label">Cheque status</td><td>{{ $payment->cheque_status?->label() ?? '—' }}</td></tr>
        </table>
    @endif

    <div class="sign">
        <div class="line">Received by — {{ $receipt->issuedBy?->name ?? 'Authorised signatory' }}</div>
    </div>

    <div class="foot">
        Issued {{ $receipt->issued_at?->format('d M Y H:i') }}. This is a computer-generated receipt.
        Financial truth is maintained against the booking's payment ledger.
    </div>
</div>
</body>
</html>
