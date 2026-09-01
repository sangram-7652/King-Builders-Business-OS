<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Actions\Buyers\CreateBuyer;
use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Exceptions\DomainException;
use App\Models\Buyer;
use App\Models\Lead;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Controlled Lead → Buyer conversion.
 *
 * Only a QUALIFIED lead can be converted. The whole operation is one
 * transaction — anything that fails rolls everything back. It is IDEMPOTENT: a
 * lead that is already CONVERTED returns its existing buyer with no further
 * side effects (no duplicate buyer, no second activity row).
 */
class ConvertLeadToBuyer
{
    use RunsInTransaction;

    public function __construct(private readonly CreateBuyer $createBuyer) {}

    /**
     * @param  array<string, mixed>|null  $newBuyerData  validated buyer payload, when creating a new buyer
     */
    public function handle(Lead $lead, User $actor, ?int $existingBuyerId = null, ?array $newBuyerData = null): Buyer
    {
        return $this->transaction(function () use ($lead, $actor, $existingBuyerId, $newBuyerData): Buyer {
            /** @var Lead $locked */
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();

            // Idempotency: already converted → hand back the buyer we linked before.
            if ($locked->status === LeadStatus::Converted) {
                if ($locked->buyer_id === null) {
                    throw new DomainException('This lead is marked converted but has no linked buyer.');
                }

                return $locked->buyer()->firstOrFail();
            }

            if ($locked->status !== LeadStatus::Qualified) {
                throw new DomainException('Only a qualified lead can be converted.');
            }

            if ($existingBuyerId !== null) {
                /** @var Buyer $buyer */
                $buyer = Buyer::query()->findOrFail($existingBuyerId);
            } elseif ($newBuyerData !== null) {
                $buyer = $this->createBuyer->handle($newBuyerData, $actor);
            } else {
                throw new DomainException('Choose an existing buyer or provide details for a new one.');
            }

            $locked->forceFill([
                'status' => LeadStatus::Converted,
                'converted_at' => now(),
                'converted_by' => $actor->id,
                'buyer_id' => $buyer->id,
            ])->save();

            $locked->recordActivity(
                LeadActivityType::Converted,
                "Converted to buyer {$buyer->customer_code}",
                ['buyer_id' => $buyer->id, 'reused_existing' => $existingBuyerId !== null],
                $actor,
            );

            Log::info('lead.converted', [
                'lead_id' => $locked->id,
                'buyer_id' => $buyer->id,
                'reused_existing' => $existingBuyerId !== null,
                'by' => $actor->id,
            ]);

            return $buyer;
        });
    }
}
