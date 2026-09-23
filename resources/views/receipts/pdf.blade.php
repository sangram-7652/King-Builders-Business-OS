<?php
/** @var \App\Models\Receipt $receipt */
/** @var \App\Support\Branding $brand */
/** @var array<string, mixed> $extra */
$payment = $receipt->payment;
$booking = $receipt->booking;
$plot = $booking?->plot;
$primary = $brand->colors['primary'];
$money = fn ($v) => '₹'.number_format((float) $v, 2);
$logoFile = $brand->logoPath ? public_path($brand->logoPath) : null;
$qrFile = $brand->qrPath ? public_path($brand->qrPath) : null;
$signatureFile = $brand->signaturePath ? public_path($brand->signaturePath) : null;
$stampFile = $brand->stampPath ? public_path($brand->stampPath) : null;
$watermarkFile = $brand->watermarkPath ? public_path($brand->watermarkPath) : null;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #1a1a1a; font-size: 11px; }
        .wrap { padding: 16px 34px; }
        .void { color: #b91c1c; font-weight: bold; border: 2px dashed #b91c1c; padding: 6px 12px; display: inline-block; margin-bottom: 10px; }

        table.head { width: 100%; border-collapse: collapse; }
        table.head td { vertical-align: top; }
        .qr { width: 72px; height: auto; display: block; }
        .qr-caption { width: 108px; font-size: 7px; color: #444; text-align: left; margin-top: 3px; line-height: 1.2; }
        .logo { height: 60px; margin-bottom: 2px; }
        .company-name { font-size: 17px; font-weight: bold; color: #111; letter-spacing: .3px; white-space: nowrap; }
        .company-contact { font-size: 9.5px; color: #444; margin-top: 3px; }
        .company-contact .nowrap { white-space: nowrap; }
        table.meta { width: 100%; border-collapse: collapse; }
        table.meta td { padding: 1px 0; font-size: 10px; }
        table.meta td.lbl { font-weight: bold; padding-right: 4px; white-space: nowrap; }
        table.meta td.val { font-weight: bold; text-align: right; }
        .rule { border-bottom: 2px solid {{ $primary }}; margin: 6px 0 10px; }
        .rule-lt { border-bottom: 1px solid #333; margin: 6px 0; }

        .customer-id { font-size: 10px; font-weight: bold; }
        .customer-name { font-size: 15px; font-weight: bold; margin-top: 2px; }
        .customer-line { font-size: 10.5px; color: #333; margin-top: 1px; }

        .section-title { font-size: 11.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px;
            color: #111; border-bottom: 1px solid #111; padding-bottom: 3px; margin: 8px 0 5px; }

        table.grid { width: 100%; border-collapse: collapse; }
        table.grid td { padding: 2px 6px 2px 0; font-size: 10.5px; vertical-align: top; line-height: 1.25; }
        table.grid td.lbl { color: #444; width: 19%; white-space: nowrap; }
        table.grid td.val { width: 31%; font-weight: bold; color: #111; }

        .welcome { margin-top: 8px; font-size: 11px; font-weight: bold; color: #111; }
        .notes { margin-top: 4px; }
        .notes .heading { font-size: 10.5px; font-weight: bold; }
        .notes ol { margin: 3px 0 0 16px; padding: 0; }
        .notes li { margin-bottom: 2.5px; font-size: 9px; line-height: 1.15; color: #333; text-align: justify; }

        .sign-block { page-break-inside: avoid; }
        .sign { margin-top: 4px; text-align: right; }
        .sign .stamp-img { height: 20px; width: auto; }
        .sign .signature-img { height: 36px; width: auto; margin-top: -10px; margin-bottom: -8px; margin-right: 6px; }
        .sign .line { display: inline-block; border-top: 1px solid #111; padding-top: 4px; font-size: 10px; font-weight: bold; }

        .footer { margin-top: 10px; border-top: 2px solid {{ $primary }}; padding-top: 6px; font-size: 9.5px; color: #333; text-align: center; }

        .watermark { position: fixed; top: 0; left: 0; width: 100%; text-align: center; z-index: -1000; }
        .watermark img { width: 280px; margin-top: 340px; opacity: 0.9; }
    </style>
</head>
<body>
    @if ($watermarkFile && file_exists($watermarkFile))
        <div class="watermark"><img src="{{ $watermarkFile }}"></div>
    @endif
<div class="wrap">

    @if ($receipt->isVoided())
        <div class="void">VOID — {{ $receipt->void_reason }}</div>
    @endif

    {{-- HEADER: letterhead centred, receipt meta right-aligned --}}
    <table class="head">
        <tr>
            <td style="width: 16%;">
                @if ($qrFile && file_exists($qrFile))
                    <img class="qr" src="{{ $qrFile }}">
                    <div class="qr-caption">IF YOU ARE PAYING VIA UPI (GOOGLE PAY / PHONEPE), KINDLY MAKE THE PLOT PAYMENT BY SCANNING THIS QR CODE.</div>
                @endif
            </td>
            <td style="width: 48%; text-align: center;">
                @if ($logoFile && file_exists($logoFile))
                    <img class="logo" src="{{ $logoFile }}"><br>
                @endif
                <div class="company-name">{{ strtoupper($brand->name) }}</div>
                @if ($brand->contact['email'] || $brand->contact['phone'])
                    <div class="company-contact">
                        @if ($brand->contact['email'])<span class="nowrap">Email:- {{ $brand->contact['email'] }}</span> @endif
                        @if ($brand->contact['phone'])<span class="nowrap">{{ $brand->contact['phone'] }}</span> @endif
                    </div>
                @endif
            </td>
            <td style="width: 36%;">
                <table class="meta">
                    <tr><td class="lbl">Receipt No:</td><td class="val">{{ $receipt->receipt_number }}</td></tr>
                    <tr><td class="lbl">Receipt Date:</td><td class="val">{{ $receipt->payment_date?->format('d/m/Y') }}</td></tr>
                    <tr><td class="lbl">Project:</td><td class="val">{{ $booking?->project?->name ?? '—' }}</td></tr>
                    <tr><td class="lbl">Booked Branch:</td><td class="val">{{ $extra['bookedBranch'] ?? '—' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    {{-- CUSTOMER INFORMATION --}}
    <div class="customer-id">CUSTOMER ID: {{ $extra['customerCode'] ?? '—' }}</div>
    <div class="customer-name">{{ strtoupper($receipt->buyer_name_snapshot) }}</div>
    @if ($extra['customerCity'] ?? null)
        <div class="customer-line">{{ $extra['customerCity'] }}</div>
    @endif
    <div class="customer-line">MOBILE NO: {{ $extra['customerMobile'] ?? '—' }}</div>
    <div class="rule-lt"></div>

    {{-- PROPERTY INFORMATION --}}
    <table class="grid">
        <tr>
            <td class="lbl">Plot No. / Project</td>
            <td class="val">{{ $plot?->plot_number ?? '—' }} ({{ $booking?->project?->name ?? '—' }})</td>
            <td class="lbl">Payment Type</td>
            <td class="val">Payment</td>
        </tr>
        <tr>
            <td class="lbl">Area</td>
            <td class="val">{{ $extra['plotArea'] ?? '—' }}</td>
            <td class="lbl">Plot Status</td>
            <td class="val">{{ $plot?->status?->label() ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Dimension</td>
            <td class="val">{{ $extra['plotDimension'] ?? '—' }}</td>
            <td class="lbl">Phase</td>
            <td class="val">{{ $extra['phase'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Plot Facing</td>
            <td class="val">{{ $extra['plotFacing'] ?? '—' }}</td>
            <td class="lbl">Additional Charges</td>
            <td class="val">{{ ($extra['additionalCharges'] ?? 0) > 0 ? $money($extra['additionalCharges']) : '—' }}</td>
        </tr>
    </table>

    <div class="section-title">Payment Details</div>
    <table class="grid">
        <tr>
            <td class="lbl">Rate</td>
            <td class="val">{{ $extra['rate'] ?? '—' }}</td>
            <td class="lbl">Chq / NEFT / RTGS No.</td>
            <td class="val">{{ $payment->reference_number ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Total Plot Amount</td>
            <td class="val">{{ $booking !== null ? $money($booking->final_amount) : '—' }}</td>
            <td class="lbl">Payee Name</td>
            <td class="val">{{ $brand->name }}</td>
        </tr>
        <tr>
            <td class="lbl">Paid Amount</td>
            <td class="val">{{ $money($receipt->amount) }} ({{ $extra['paidAmountInWords'] ?? '—' }})</td>
            <td class="lbl">Chq / NEFT / RTGS Date</td>
            <td class="val">{{ $receipt->payment_date?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="lbl">Total Paid Amount</td>
            <td class="val">{{ isset($extra['totalPaidAmount']) ? $money($extra['totalPaidAmount']) : '—' }}</td>
            <td class="lbl">Bank Name</td>
            <td class="val">{{ $payment->cheque_bank_name ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Balance Amount</td>
            <td class="val">{{ isset($extra['balanceAmount']) ? $money($extra['balanceAmount']) : '—' }}</td>
            <td class="lbl">Remark</td>
            <td class="val">{{ $extra['discountRemark'] ?: ($payment->notes ?: '—') }}</td>
        </tr>
        <tr>
            <td class="lbl">Payment Mode</td>
            <td class="val" colspan="3">{{ $receipt->payment_mode_label }}</td>
        </tr>
    </table>

    <div class="welcome">Welcome to {{ $brand->name }} &amp; thank you for associating with us.</div>

    <div class="notes">
        <div class="heading">Note —</div>
        <ol>
            <li>The receipt is subject to realization of cash,cheque and DD.</li>
            <li>This is merely a receipt against the cheque/draft/pay order recieved by the company based on information furnished by the applicant in the application and the allotment pursuant thereto is purely provisional and does not entitile the applicant to claim any right, title or interest of any nature whosoever over the land/Property.</li>
            <li>In case the cheque comprising booking amount is dishonored due to any reason whatsoever the applicant shall be deemed to be null and void and the allotment, if any, shall stand automatically cancelled/revoked/withdrawn without any notice to the applicant.</li>
            <li>This is computer generated receipt.No stamp required.</li>
            <li>Payment will be accept from the account of the client in whose name the registry is done.</li>
            @if ($brand->hasBankAccount())
                <li>
                    Kindly deposit all payments only into the company's official bank account. The account details are provided below:
                    Company Name: {{ $brand->bank['account_name'] ?: $brand->name }}
                    Account Number: {{ $brand->bank['account_number'] }}
                    @if ($brand->bank['ifsc']) IFSC Code: {{ $brand->bank['ifsc'] }} @endif
                    @if ($brand->bank['name']) Bank Name: {{ $brand->bank['name'] }} @endif
                    @if ($brand->bank['branch']) Branch: {{ $brand->bank['branch'] }} @endif
                    Payments made to any account other than the one mentioned above will not be accepted. The company will not be responsible for any amount paid to an unauthorized account. Thank you for your cooperation. Sincerely, {{ rtrim($brand->bank['account_name'] ?: $brand->name, '.') }}.
                </li>
            @endif
            <li>If the booking amount paid is less than 25% of the total plot value, the customer must clear the balance amount within 30 days from the booking date. If the customer fails to complete the plot registration within 40 days from the booking date, the booking will be automatically cancelled without any prior notice.</li>
        </ol>
    </div>

    <div class="sign-block">
        <div class="sign">
            @if ($stampFile && file_exists($stampFile))
                <img class="stamp-img" src="{{ $stampFile }}"><br>
            @endif
            @if ($signatureFile && file_exists($signatureFile))
                <img class="signature-img" src="{{ $signatureFile }}"><br>
            @endif
            <div class="line">(AUTHORISED SIGNATORY)</div>
        </div>

        <div class="footer">
            @if ($brand->contact['head_office_address'])
                Head Office:- {{ $brand->contact['head_office_address'] }}<br>
            @endif
            @if ($brand->contact['email'] || $brand->contact['website'])
                @if ($brand->contact['email']) Email:- {{ $brand->contact['email'] }} @endif
                @if ($brand->contact['email'] && $brand->contact['website']) , @endif
                @if ($brand->contact['website']) Website:- {{ $brand->contact['website'] }} @endif
            @endif
        </div>
    </div>
</div>
</body>
</html>
