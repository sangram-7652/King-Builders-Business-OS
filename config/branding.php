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

    // Any CSS colour value (hex, rgb, hsl, color-mix, ...).
    'colors' => [
        'primary' => env('BRAND_PRIMARY', '#2563eb'),
        'primary_fg' => env('BRAND_PRIMARY_FG', '#ffffff'),
        'primary_hover' => env('BRAND_PRIMARY_HOVER', '#1d4ed8'),
        'accent' => env('BRAND_ACCENT', '#0ea5e9'),
    ],
];
