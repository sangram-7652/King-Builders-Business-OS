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
 * Publishes a DRAFT commission scheme version (M14.3).
 *
 *   - the version must have a default rule (project overrides are optional)
 *   - any currently-PUBLISHED version of the same family is ARCHIVED first, so
 *     at most one version is ever in force
 *   - once published the version is FROZEN — no further edits to it, its rules
 *     or its slabs — so a commission snapshot against it stays reproducible
 *
 * Row-locked and idempotent (re-publishing an already-published version is a
 * no-op).
 */
class PublishCommissionScheme
{
    use RunsInTransaction;

    public function handle(CommissionScheme $scheme, User $actor): CommissionScheme
    {
        return $this->transaction(function () use ($scheme, $actor): CommissionScheme {
            /** @var CommissionScheme $locked */
            $locked = CommissionScheme::query()->whereKey($scheme->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === CommissionSchemeStatus::Published) {
                return $locked;
            }

            if ($locked->status !== CommissionSchemeStatus::Draft) {
                throw new DomainException("A {$locked->status->label()} scheme version cannot be published.");
            }

            if ($locked->defaultRule()->doesntExist()) {
                throw new DomainException('Add a default calculation rule before publishing this scheme.');
            }

            // Retire the version currently in force for this family.
            CommissionScheme::query()
                ->where('code', $locked->code)
                ->whereKeyNot($locked->getKey())
                ->where('status', CommissionSchemeStatus::Published->value)
                ->lockForUpdate()
                ->each(function (CommissionScheme $previous) use ($actor): void {
                    $previous->forceFill([
                        'status' => CommissionSchemeStatus::Archived,
                        'archived_at' => now(),
                    ])->save();

                    Log::info('commission_scheme.superseded', [
                        'scheme_id' => $previous->id, 'by' => $actor->id,
                    ]);
                });

            $locked->forceFill([
                'status' => CommissionSchemeStatus::Published,
                'published_at' => now(),
                'published_by' => $actor->id,
                'effective_from' => $locked->effective_from ?? now()->toDateString(),
            ])->save();

            Log::info('commission_scheme.published', [
                'scheme_id' => $locked->id, 'code' => $locked->code, 'version' => $locked->version, 'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
