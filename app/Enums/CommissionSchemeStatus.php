<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle of a commission scheme version (M14.3).
 *
 *   DRAFT ─▶ PUBLISHED ─▶ ARCHIVED
 *     └──────────────────▶ ARCHIVED
 *
 * A DRAFT is fully editable. Once PUBLISHED a version is FROZEN — its rules and
 * slabs can never change, so a commission snapshot taken against it stays
 * reproducible for ever. Changing the numbers means creating a new version.
 */
enum CommissionSchemeStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Published => 'success',
            self::Archived => 'warning',
        };
    }

    /** @return array<value-of<self>, list<self>> */
    public static function transitionMap(): array
    {
        return [
            self::Draft->value => [self::Published, self::Archived],
            self::Published->value => [self::Archived],
            self::Archived->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitionMap()[$this->value], true);
    }

    /** A frozen version — no edits to the scheme, its rules or its slabs. */
    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
