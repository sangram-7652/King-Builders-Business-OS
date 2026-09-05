<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\CommissionSchemeStatus;
use App\Exceptions\DomainException;
use App\Models\CommissionScheme;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Archives a DRAFT or PUBLISHED commission scheme version (M14.3). An archived
 * version is retired and frozen — existing commission snapshots that reference
 * it are unaffected.
 */
class ArchiveCommissionScheme
{
    use RunsInTransaction;

    public function handle(CommissionScheme $scheme, User $actor): CommissionScheme
    {
        return $this->transaction(function () use ($scheme, $actor): CommissionScheme {
            /** @var CommissionScheme $locked */
            $locked = CommissionScheme::query()->whereKey($scheme->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === CommissionSchemeStatus::Archived) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(CommissionSchemeStatus::Archived)) {
                throw new DomainException("A {$locked->status->label()} scheme version cannot be archived.");
            }

            $locked->forceFill([
                'status' => CommissionSchemeStatus::Archived,
                'archived_at' => now(),
            ])->save();

            Log::info('commission_scheme.archived', ['scheme_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }
}
