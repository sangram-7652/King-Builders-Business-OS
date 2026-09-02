<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Physical possession handover to the customer (M10).
 *
 *   READY_FOR_HANDOVER ─▶ HANDOVER ─▶ ACKNOWLEDGEMENT ─▶ COMPLETED
 *
 * COMPLETED is terminal — a completed handover is never duplicated.
 */
enum PossessionHandoverStatus: string
{
    use HasLabel;

    case ReadyForHandover = 'ready_for_handover';
    case Handover = 'handover';
    case Acknowledgement = 'acknowledgement';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::ReadyForHandover => 'Ready for handover',
            self::Handover => 'Handover',
            self::Acknowledgement => 'Acknowledgement',
            self::Completed => 'Completed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ReadyForHandover => 'info',
            self::Handover => 'warning',
            self::Acknowledgement => 'brand',
            self::Completed => 'success',
        };
    }

    /** @return array<value-of<self>, list<self>> */
    public static function transitionMap(): array
    {
        return [
            self::ReadyForHandover->value => [self::Handover],
            self::Handover->value => [self::Acknowledgement],
            self::Acknowledgement->value => [self::Completed],
            self::Completed->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitionMap()[$this->value], true);
    }
}
