<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Enums\PartnerActivityType;
use App\Enums\PartnerStatus;
use App\Exceptions\DomainException;
use App\Models\Partner;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Map-enforced partner status change (M14). Row-locked and idempotent — moving
 * a partner to the state it is already in is a no-op. Reaching ACTIVE from
 * DRAFT / PENDING stamps the approval fields.
 */
class ChangePartnerStatusAction
{
    use RunsInTransaction;

    public function handle(Partner $partner, PartnerStatus $target, User $actor, ?string $reason = null): Partner
    {
        return $this->transaction(function () use ($partner, $target, $actor, $reason): Partner {
            /** @var Partner $locked */
            $locked = Partner::query()->whereKey($partner->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            if ($current === $target) {
                return $locked;
            }

            if (! $current->canTransitionTo($target)) {
                throw new DomainException("A {$current->label()} partner cannot move to {$target->label()}.");
            }

            $wasApproved = $locked->approved_at !== null;

            $locked->forceFill([
                'status' => $target,
                'status_changed_at' => now(),
                'status_reason' => $reason,
            ]);

            if ($target === PartnerStatus::Active && ! $wasApproved) {
                $locked->forceFill(['approved_at' => now(), 'approved_by' => $actor->id]);
            }

            $locked->save();

            $locked->recordActivity(
                $this->activityTypeFor($current, $target),
                "Status: {$current->label()} → {$target->label()}",
                array_filter([
                    'from' => $current->value,
                    'to' => $target->value,
                    'reason' => $reason,
                ], fn ($v) => $v !== null),
                $actor,
            );

            Log::info('partner.status_changed', [
                'partner_id' => $locked->id,
                'from' => $current->value,
                'to' => $target->value,
                'by' => $actor->id,
            ]);

            return $locked;
        });
    }

    private function activityTypeFor(PartnerStatus $from, PartnerStatus $to): PartnerActivityType
    {
        return match (true) {
            $to === PartnerStatus::Pending => PartnerActivityType::SubmittedForApproval,
            $to === PartnerStatus::Active && in_array($from, [PartnerStatus::Draft, PartnerStatus::Pending], true) => PartnerActivityType::Approved,
            $to === PartnerStatus::Active && $from === PartnerStatus::OnHold => PartnerActivityType::Resumed,
            $to === PartnerStatus::Active => PartnerActivityType::Reactivated,
            $to === PartnerStatus::OnHold => PartnerActivityType::PutOnHold,
            $to === PartnerStatus::Suspended => PartnerActivityType::Suspended,
            $to === PartnerStatus::Blacklisted => PartnerActivityType::Blacklisted,
            $to === PartnerStatus::Inactive => PartnerActivityType::Retired,
            default => PartnerActivityType::StatusChanged,
        };
    }
}
