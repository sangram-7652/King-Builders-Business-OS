<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Enums\LeadActivityType;
use App\Enums\PartnerActivityType;
use App\Exceptions\DomainException;
use App\Models\Lead;
use App\Models\LeadPartnerAttribution;
use App\Models\Partner;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Attributes a lead to a channel partner (M14.2), or clears it (`$partner`
 * null = an explicit "direct / no partner").
 *
 * Row-locked and idempotent. The previous attribution span is closed
 * (`ended_at` stamped) and a new one opened — the history in
 * `lead_partner_attributions` is never destroyed. Only an ACTIVE partner may
 * receive a new attribution.
 */
class AttributeLeadToPartner
{
    use RunsInTransaction;

    /**
     * @param  array{source?: string|null, reason?: string|null}  $meta
     */
    public function handle(Lead $lead, ?Partner $partner, User $actor, array $meta = []): Lead
    {
        if ($partner !== null && ! $partner->canReceiveAttribution()) {
            throw new DomainException("A {$partner->status->label()} partner cannot be attributed.");
        }

        return $this->transaction(function () use ($lead, $partner, $actor, $meta): Lead {
            /** @var Lead $locked */
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();

            $previousPartnerId = $locked->partner_id;
            $targetPartnerId = $partner?->id;

            if ($previousPartnerId === $targetPartnerId) {
                return $locked;
            }

            LeadPartnerAttribution::query()
                ->where('lead_id', $locked->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);

            LeadPartnerAttribution::create([
                'lead_id' => $locked->id,
                'partner_id' => $targetPartnerId,
                'attributed_by' => $actor->id,
                'attributed_at' => now(),
                'source' => $meta['source'] ?? 'manual',
                'reason' => $meta['reason'] ?? null,
            ]);

            $locked->forceFill(['partner_id' => $targetPartnerId])->save();

            if ($partner !== null) {
                $locked->recordActivity(LeadActivityType::PartnerAttributed, "Attributed to {$partner->displayName()}.", [
                    'partner_id' => $partner->id,
                ], $actor);
                $partner->recordActivity(PartnerActivityType::LeadAttributed, "Lead {$locked->name} attributed.", [
                    'lead_id' => $locked->id,
                ], $actor);
            } else {
                $locked->recordActivity(LeadActivityType::PartnerAttributionRemoved, 'Marked as a direct lead (no partner).', [], $actor);
            }

            if ($previousPartnerId !== null && $previousPartnerId !== $targetPartnerId) {
                Partner::find($previousPartnerId)?->recordActivity(
                    PartnerActivityType::LeadAttributionRemoved,
                    "Lead {$locked->name} attribution removed.",
                    ['lead_id' => $locked->id],
                    $actor,
                );
            }

            Log::info('lead.partner_attributed', [
                'lead_id' => $locked->id,
                'from' => $previousPartnerId,
                'to' => $targetPartnerId,
                'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
