<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Document handover workflow (M9).
 *
 *   REGISTRY_COMPLETED ─▶ DOCUMENTS_READY ─▶ HANDOVER_SCHEDULED ─▶ HANDED_OVER
 *
 * A completed handover is terminal — re-completing is a no-op; correcting one
 * needs the dedicated reversal ability.
 */
enum HandoverStatus: string
{
    use HasLabel;

    case RegistryCompleted = 'registry_completed';
    case DocumentsReady = 'documents_ready';
    case HandoverScheduled = 'handover_scheduled';
    case HandedOver = 'handed_over';

    public function label(): string
    {
        return match ($this) {
            self::RegistryCompleted => 'Registry completed',
            self::DocumentsReady => 'Documents ready',
            self::HandoverScheduled => 'Handover scheduled',
            self::HandedOver => 'Handed over',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::RegistryCompleted => 'muted',
            self::DocumentsReady => 'info',
            self::HandoverScheduled => 'warning',
            self::HandedOver => 'success',
        };
    }

    /** @return array<value-of<self>, list<self>> */
    public static function transitionMap(): array
    {
        return [
            self::RegistryCompleted->value => [self::DocumentsReady],
            self::DocumentsReady->value => [self::HandoverScheduled, self::HandedOver],
            self::HandoverScheduled->value => [self::HandedOver],
            self::HandedOver->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitionMap()[$this->value], true);
    }
}
