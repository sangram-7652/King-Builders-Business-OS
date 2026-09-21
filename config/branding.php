<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Branding
| ---------------------------------------------------------------------------
| Project / tenant specific branding. Consumed by App\Support\Branding and
| exposed to the UI as CSS custom properties (see <x-app.branding-style>).
|
| M0 sources these from env. A later milestone can override them per-project
| by binding a different Branding resolver — the UI never needs to change.
*/

return [
    'name' => env('BRAND_NAME', 'King Builders'),

    'logo_path' => env('BRAND_LOGO_PATH'),

    // Payment-collection QR code (e.g. a static UPI QR) printed on receipts.
    // Relative to public/, same convention as logo_path. Left empty by
    // default — the receipt only prints the QR block when this is set.
    'qr_path' => env('BRAND_QR_PATH'),

    // Authorised-signatory stamp + handwritten signature images printed on
    // receipts, above the "(AUTHORISED SIGNATORY)" line. Relative to
    // public/, same convention as logo_path. Left empty by default.
    'stamp_path' => env('BRAND_STAMP_PATH'),
    'signature_path' => env('BRAND_SIGNATURE_PATH'),

    // Faint background watermark printed behind the receipt content.
    // Relative to public/, same convention as logo_path. Left empty by
    // default — the receipt only prints the watermark when this is set.
    'watermark_path' => env('BRAND_WATERMARK_PATH'),

    // Any CSS colour value (hex, rgb, hsl, color-mix, ...).
    'colors' => [
        'primary' => env('BRAND_PRIMARY', '#2563eb'),
        'primary_fg' => env('BRAND_PRIMARY_FG', '#ffffff'),
        'primary_hover' => env('BRAND_PRIMARY_HOVER', '#1d4ed8'),
        'accent' => env('BRAND_ACCENT', '#0ea5e9'),
    ],

    // Contact / letterhead details for printed documents (receipts, etc.).
    'contact' => [
        'email' => env('BRAND_EMAIL'),
        'phone' => env('BRAND_PHONE'),
        'website' => env('BRAND_WEBSITE'),
        'head_office_address' => env('BRAND_HEAD_OFFICE_ADDRESS'),

        // Seller / company identity for the Plot KYC Receipt ("Seller /
        // Company" section). Company name is `name` above, address is
        // `head_office_address` above, and mobile is `phone` above — only
        // the director's name and the company PAN are genuinely new.
        'director_name' => env('BRAND_DIRECTOR_NAME'),
        'pan_number' => env('BRAND_PAN_NUMBER'),
    ],

    // Official bank account to print on receipts, if the business has
    // confirmed one. Left empty by default — the receipt template only
    // prints the "deposit only into our official account" notice when
    // `account_number` is set, so an unconfirmed/wrong account is never
    // shown by accident.
    'bank' => [
        'name' => env('BRAND_BANK_NAME'),
        'account_name' => env('BRAND_BANK_ACCOUNT_NAME'),
        'account_number' => env('BRAND_BANK_ACCOUNT_NUMBER'),
        'ifsc' => env('BRAND_BANK_IFSC'),
        'branch' => env('BRAND_BANK_BRANCH'),
    ],
];
