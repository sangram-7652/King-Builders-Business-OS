<?php

declare(strict_types=1);

namespace App\Support\Registry;

/**
 * The structured outcome of a registry eligibility evaluation (M9).
 */
final class EligibilityResult
{
    /**
     * @param  list<array{key: string, label: string, passed: bool, detail: string|null}>  $checks
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $checks,
    ) {}

    /** @return list<string> reasons a booking is NOT yet eligible */
    public function reasons(): array
    {
        return array_values(array_map(
            fn ($c) => $c['detail'] ?? $c['label'],
            array_filter($this->checks, fn ($c) => ! $c['passed']),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'checks' => $this->checks,
            'reasons' => $this->reasons(),
            'evaluated_at' => now()->toIso8601String(),
        ];
    }
}
