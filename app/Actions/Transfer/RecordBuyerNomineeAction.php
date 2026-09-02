<?php

declare(strict_types=1);

namespace App\Actions\Transfer;

use App\Enums\PossessionActivityType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\BuyerNominee;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Records / changes a buyer's nominee (M10 foundation). `transfer.create`.
 *
 * A nominee is NOT an owner — this never touches `plot_ownership_history`. A
 * change supersedes the current active nominee(s) (append-only) instead of
 * editing them.
 */
class RecordBuyerNomineeAction
{
    use RunsInTransaction;

    /**
     * @param  array{name: string, relation?: string|null, phone?: string|null, share_percentage?: string|int|float|null, notes?: string|null}  $data
     */
    public function handle(Buyer $buyer, array $data, User $actor, ?Booking $booking = null): BuyerNominee
    {
        if (! $actor->can('transfer.create')) {
            throw new DomainException('You are not authorised to record nominees.');
        }

        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new DomainException('The nominee name is required.');
        }

        return $this->transaction(function () use ($buyer, $data, $actor, $booking): BuyerNominee {
            $nominee = BuyerNominee::create([
                'buyer_id' => $buyer->id,
                'booking_id' => $booking?->id,
                'name' => trim($data['name']),
                'relation' => $data['relation'] ?? null,
                'phone' => $data['phone'] ?? null,
                'share_percentage' => $data['share_percentage'] ?? null,
                'status' => BuyerNominee::STATUS_ACTIVE,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            BuyerNominee::query()
                ->where('buyer_id', $buyer->id)
                ->when($booking !== null, fn ($q) => $q->where('booking_id', $booking->id), fn ($q) => $q->whereNull('booking_id'))
                ->whereKeyNot($nominee->getKey())
                ->where('status', BuyerNominee::STATUS_ACTIVE)
                ->update(['status' => BuyerNominee::STATUS_SUPERSEDED, 'superseded_by' => $nominee->id]);

            PossessionTimeline::record(
                PossessionActivityType::NomineeRecorded,
                "Nominee recorded for {$buyer->fullName()}.",
                $booking, $booking?->plot, $buyer,
                ['nominee_id' => $nominee->id],
                $actor,
            );

            Log::info('buyer_nominee.recorded', ['nominee_id' => $nominee->id, 'buyer_id' => $buyer->id, 'by' => $actor->id]);

            return $nominee;
        });
    }
}
