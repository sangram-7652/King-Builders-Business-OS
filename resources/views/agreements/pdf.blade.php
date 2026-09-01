@php
    /** @var \App\Models\Agreement $agreement */
    $b = $agreement->booking;
    $snap = $agreement->terms_snapshot ?? [];
    $primary = $brand['primary'] ?? '#2563eb';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #1f2933; font-size: 12px; line-height: 1.5; }
        .wrap { padding: 36px 44px; }
        .head { border-bottom: 3px solid {{ $primary }}; padding-bottom: 12px; margin-bottom: 20px; }
        .company { font-size: 20px; font-weight: bold; color: {{ $primary }}; }
        h1 { font-size: 16px; letter-spacing: 1px; margin: 18px 0 4px; }
        .num { color: #52606d; font-size: 12px; }
        table.kv { width: 100%; border-collapse: collapse; margin: 12px 0; }
        table.kv td { padding: 5px 8px; vertical-align: top; border-bottom: 1px solid #e4e7eb; }
        table.kv td.label { color: #7b8794; width: 34%; }
        .section-title { margin-top: 20px; font-weight: bold; color: {{ $primary }}; text-transform: uppercase; letter-spacing: 1px; font-size: 11px; }
        .draft { color: #b45309; border: 2px dashed #b45309; padding: 4px 10px; display: inline-block; margin-bottom: 10px; font-weight: bold; }
        .sign-row { margin-top: 60px; }
        .sign-row td { width: 45%; padding-top: 6px; border-top: 1px solid #1f2933; font-size: 11px; }
        .foot { margin-top: 36px; border-top: 1px solid #cbd2d9; padding-top: 10px; color: #7b8794; font-size: 10px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="company">{{ $brand['name'] }}</div>
        <div style="color:#7b8794">{{ $brand['name'] }} Business OS</div>
    </div>

    @if (! $agreement->isSigned())
        <div class="draft">DRAFT — {{ $agreement->status->label() }}</div>
    @endif

    <h1>{{ strtoupper($agreement->type->label()) }}</h1>
    <div class="num">{{ $agreement->agreement_number }} &middot; prepared {{ ($agreement->prepared_at ?? now())->format('d M Y') }}</div>

    <div class="section-title">Parties &amp; property</div>
    <table class="kv">
        <tr><td class="label">Booking</td><td>{{ $b->booking_number }}</td></tr>
        <tr><td class="label">Project</td><td>{{ $b->project?->name ?? '—' }}</td></tr>
        <tr><td class="label">Plot</td><td>{{ $b->block?->name }} / Plot {{ $b->plot?->plot_number }} &middot; {{ $b->plot?->areaLabel() }}</td></tr>
        <tr><td class="label">Booking date</td><td>{{ $b->booking_date?->format('d M Y') }}</td></tr>
        @foreach ($buyers as $bb)
            <tr><td class="label">{{ $bb['is_primary'] ? 'Primary buyer' : 'Co-buyer' }}</td>
                <td>{{ $bb['name'] }} ({{ $bb['customer_code'] }}) &middot; {{ $bb['ownership'] }}%</td></tr>
        @endforeach
    </table>

    <div class="section-title">Consideration (as per the booking, {{ data_get($snap, 'frozen_at') ? \Illuminate\Support\Carbon::parse(data_get($snap,'frozen_at'))->format('d M Y') : 'current' }})</div>
    <table class="kv">
        <tr><td class="label">Base amount</td><td>&#8377; {{ number_format((float) data_get($snap, 'base_amount', $b->base_amount), 2) }}</td></tr>
        <tr><td class="label">PLC</td><td>&#8377; {{ number_format((float) data_get($snap, 'plc_amount', $b->plc_amount), 2) }}</td></tr>
        <tr><td class="label">Other charges</td><td>&#8377; {{ number_format((float) data_get($snap, 'charge_amount', $b->charge_amount), 2) }}</td></tr>
        <tr><td class="label">Discount</td><td>&#8377; {{ number_format((float) data_get($snap, 'discount_amount', $b->discount_amount), 2) }}</td></tr>
        <tr><td class="label">Tax</td><td>&#8377; {{ number_format((float) data_get($snap, 'tax_amount', $b->tax_amount), 2) }}</td></tr>
        <tr><td class="label"><strong>Total consideration</strong></td>
            <td><strong>&#8377; {{ number_format((float) data_get($snap, 'final_amount', $b->final_amount), 2) }}</strong></td></tr>
    </table>

    <div class="section-title">Terms</div>
    <p>
        This agreement records the sale of the above plot to the buyer(s) for the total consideration
        stated, payable as per the agreed payment plan. It references the historical booking terms and
        does not alter them. Registration is subject to completion of documentation and payment
        prerequisites.
    </p>

    <table class="sign-row">
        <tr>
            <td>For {{ $brand['name'] }} — {{ $agreement->preparedBy?->name ?? 'Authorised signatory' }}</td>
            <td>&nbsp;</td>
            <td>Buyer — {{ $agreement->signed_by ?? '________________________' }}</td>
        </tr>
    </table>

    <div class="foot">
        {{ $agreement->agreement_number }} &middot; computer-generated {{ now()->format('d M Y H:i') }}. Financial figures come from the M6 booking snapshot.
    </div>
</div>
</body>
</html>
