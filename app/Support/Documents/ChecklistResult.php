<?php

declare(strict_types=1);

namespace App\Support\Documents;

/**
 * A derived document checklist (M9). Completion counts are NEVER stored — they
 * are recomputed from requirements + documents on every read.
 */
final class ChecklistResult
{
    /**
     * @param  list<array{document_type_id: int, name: string, required: bool, sequence: int, document_id: int|null, status: string, missing: bool}>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $requiredCount,
        public readonly int $receivedCount,
        public readonly int $verifiedCount,
        public readonly int $rejectedCount,
        public readonly int $pendingCount,
    ) {}

    public function isComplete(): bool
    {
        return $this->requiredCount > 0 && $this->verifiedCount >= $this->requiredCount;
    }

    /** @return list<array<string, mixed>> */
    public function missing(): array
    {
        return array_values(array_filter($this->items, fn ($i) => $i['missing']));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'required' => $this->requiredCount,
            'received' => $this->receivedCount,
            'verified' => $this->verifiedCount,
            'rejected' => $this->rejectedCount,
            'pending' => $this->pendingCount,
            'complete' => $this->isComplete(),
            'items' => $this->items,
        ];
    }
}
