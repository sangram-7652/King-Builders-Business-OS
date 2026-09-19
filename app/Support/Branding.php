<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Resolves the active project/tenant branding and renders it as CSS custom
 * properties so the whole UI can be re-skinned without touching a component.
 *
 * Registered as a singleton in AppServiceProvider and shared with every view
 * as `$branding`.
 */
final class Branding implements Htmlable
{
    /**
     * @param  array{primary:string,primary_fg:string,primary_hover:string,accent:string}  $colors
     * @param  array{email:?string,phone:?string,website:?string,head_office_address:?string}  $contact
     * @param  array{name:?string,account_name:?string,account_number:?string,ifsc:?string,branch:?string}  $bank
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $logoPath,
        public readonly array $colors,
        public readonly array $contact = ['email' => null, 'phone' => null, 'website' => null, 'head_office_address' => null],
        public readonly array $bank = ['name' => null, 'account_name' => null, 'account_number' => null, 'ifsc' => null, 'branch' => null],
        public readonly ?string $qrPath = null,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string,mixed> $config */
        $config = config('branding');

        return new self(
            name: (string) ($config['name'] ?? 'King Builders'),
            logoPath: $config['logo_path'] ?? null,
            qrPath: $config['qr_path'] ?? null,
            colors: [
                'primary' => (string) data_get($config, 'colors.primary', '#2563eb'),
                'primary_fg' => (string) data_get($config, 'colors.primary_fg', '#ffffff'),
                'primary_hover' => (string) data_get($config, 'colors.primary_hover', '#1d4ed8'),
                'accent' => (string) data_get($config, 'colors.accent', '#0ea5e9'),
            ],
            contact: [
                'email' => data_get($config, 'contact.email'),
                'phone' => data_get($config, 'contact.phone'),
                'website' => data_get($config, 'contact.website'),
                'head_office_address' => data_get($config, 'contact.head_office_address'),
            ],
            bank: [
                'name' => data_get($config, 'bank.name'),
                'account_name' => data_get($config, 'bank.account_name'),
                'account_number' => data_get($config, 'bank.account_number'),
                'ifsc' => data_get($config, 'bank.ifsc'),
                'branch' => data_get($config, 'bank.branch'),
            ],
        );
    }

    public function hasBankAccount(): bool
    {
        return filled($this->bank['account_number'] ?? null);
    }

    public function initials(): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($this->name)) ?: []));

        $letters = match (count($words)) {
            0 => 'KB',
            1 => mb_substr($words[0], 0, 2),
            default => mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1),
        };

        return mb_strtoupper($letters);
    }

    /** @return array<string,string> */
    public function cssVariables(): array
    {
        return [
            '--brand-primary' => $this->colors['primary'],
            '--brand-primary-fg' => $this->colors['primary_fg'],
            '--brand-primary-hover' => $this->colors['primary_hover'],
            '--brand-accent' => $this->colors['accent'],
            '--brand-ring' => "color-mix(in srgb, {$this->colors['primary']} 45%, transparent)",
        ];
    }

    /** Renders a `<style>` block that overrides the fallback tokens in app.css. */
    public function toHtml(): string
    {
        $declarations = collect($this->cssVariables())
            ->map(fn (string $value, string $prop): string => "{$prop}: {$value};")
            ->implode(' ');

        return (new HtmlString("<style>:root { {$declarations} }</style>"))->toHtml();
    }
}
