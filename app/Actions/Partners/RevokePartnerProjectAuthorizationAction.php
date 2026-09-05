<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Enums\PartnerActivityType;
use App\Models\PartnerProjectAuthorization;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Revokes a partner's authorisation for a project (M14). The row is kept and
 * flipped to `revoked` — never deleted — so the grant/revoke history survives.
 * Existing attribution / commission on past bookings is unaffected.
 */
class RevokePartnerProjectAuthorizationAction
{
    use RunsInTransaction;

    public function handle(PartnerProjectAuthorization $authorization, User $actor, ?string $reason = null): PartnerProjectAuthorization
    {
        return $this->transaction(function () use ($authorization, $actor, $reason): PartnerProjectAuthorization {
            /** @var PartnerProjectAuthorization $locked */
            $locked = PartnerProjectAuthorization::query()
                ->whereKey($authorization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'revoked') {
                return $locked;
            }

            $locked->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoke_reason' => $reason,
            ])->save();

            $locked->loadMissing('project', 'partner');

            $locked->partner?->recordActivity(
                PartnerActivityType::ProjectRevoked,
                "Authorisation revoked for {$locked->project?->name}.",
                array_filter(['project_id' => $locked->project_id, 'reason' => $reason], fn ($v) => $v !== null),
                $actor,
            );

            Log::info('partner.project_revoked', [
                'partner_id' => $locked->partner_id, 'project_id' => $locked->project_id, 'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
