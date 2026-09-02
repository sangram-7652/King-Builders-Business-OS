<?php

declare(strict_types=1);

namespace App\Support\Possession;

/**
 * A derived possession readiness checklist (M10). Nothing here is stored — it is
 * recomputed from eligibility + clearances + the latest inspection on every
 * read. No completion percentage is persisted.
 */
final class PossessionChecklist
{
    /**
     * @param  list<array{key: string, label: string, category: string, required: bool, done: bool, detail: string|null}>  $items
     */
    public function __construct(public readonly array $items) {}

    /** @return list<array<string, mixed>> */
    public function outstanding(): array
    {
        return array_values(array_filter($this->items, fn ($i) => $i['required'] && ! $i['done']));
    }

    public function isComplete(): bool
    {
        return $this->outstanding() === [];
    }

    public function requiredCount(): int
    {
        return count(array_filter($this->items, fn ($i) => $i['required']));
    }

    public function doneCount(): int
    {
        return count(array_filter($this->items, fn ($i) => $i['required'] && $i['done']));
    }

    /** @return list<string> */
    public function reasons(): array
    {
        return array_values(array_map(fn ($i) => $i['detail'] ?? $i['label'], $this->outstanding()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'complete' => $this->isComplete(),
            'required' => $this->requiredCount(),
            'done' => $this->doneCount(),
            'items' => $this->items,
            'reasons' => $this->reasons(),
        ];
    }
}
