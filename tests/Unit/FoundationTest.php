<?php

declare(strict_types=1);

use App\Enums\Concerns\HasLabel;
use App\Support\Branding;

it('builds branding from config and renders CSS variables', function () {
    config()->set('branding', [
        'name' => 'Acme Estates',
        'logo_path' => null,
        'colors' => [
            'primary' => '#ff0000',
            'primary_fg' => '#ffffff',
            'primary_hover' => '#cc0000',
            'accent' => '#00ff00',
        ],
    ]);

    $branding = Branding::fromConfig();

    expect($branding->name)->toBe('Acme Estates')
        ->and($branding->initials())->toBe('AE')
        ->and($branding->cssVariables()['--brand-primary'])->toBe('#ff0000')
        ->and((string) $branding->toHtml())->toContain('--brand-primary: #ff0000;');
});

it('derives options and values from the HasLabel enum trait', function () {
    expect(FoundationTestStatus::options())->toBe(['draft' => 'Draft', 'active' => 'Active'])
        ->and(FoundationTestStatus::values())->toBe(['draft', 'active']);
});

enum FoundationTestStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Active = 'active';
}
