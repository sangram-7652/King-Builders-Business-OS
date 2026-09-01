<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * Shared helpers for backed enums used across the Business OS.
 *
 * Convention: every domain enum is a string-backed enum, implements its own
 * `label()` (human text) and, where relevant, `color()` (a UI badge variant),
 * and uses this trait for the collection helpers.
 */
trait HasLabel
{
    /** Fallback label derived from the case name; override per-enum for real copy. */
    public function label(): string
    {
        return Str::headline(mb_strtolower($this->name));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => (string) $case->value, self::cases());
    }

    /** @return array<string,string> value => label, suitable for <select> options */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[(string) $case->value] = $case->label();
        }

        return $options;
    }
}
