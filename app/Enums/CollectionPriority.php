<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Collection case priority (M8). Deterministic, rule-based — NOT a scoring
 * model. See App\Services\Collections\CollectionPrioritizer.
 */
enum CollectionPriority: string
{
    use HasLabel;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'muted',
            self::Medium => 'info',
            self::High => 'warning',
            self::Critical => 'danger',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 6 => self::Critical,
            $score >= 4 => self::High,
            $score >= 2 => self::Medium,
            default => self::Low,
        };
    }
}
