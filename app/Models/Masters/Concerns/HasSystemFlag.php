<?php

declare(strict_types=1);

namespace App\Models\Masters\Concerns;

/**
 * For masters with an `is_system` column: seeded reference rows that users may
 * deactivate but not delete or rename away from their canonical value.
 *
 * @property bool $is_system
 */
trait HasSystemFlag
{
    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }
}
