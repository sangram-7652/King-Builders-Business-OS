<?php

declare(strict_types=1);

namespace App\Actions\Transfer;

use App\Enums\PossessionActivityType;
use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\Ownership\PlotOwnershipService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Creates a DRAFT transfer request for a CONFIRMED booking (M10). `transfer.create`.
 * `request_number` (TRF-000001) is concurrency-safe. At most one active
 * (blocking) transfer per booking.
 */
class CreateTransferRequestAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly PlotOwnershipService $ownership,
    ) {}

    /**
     * @param  array{new_buyer_id?: int|null, reason?: string|null, notes?: string|null}  $data
     */
    public function handle(Booking $booking, TransferType $type, array $data, User $actor): TransferRequest
    {
        if (! $actor->can('transfer.create')) {
            throw new DomainException('You are not authorised to raise transfer requests.');
        }

        if (! $booking->isConfirmed()) {
            throw new DomainException('A transfer can only be raised for a confirmed booking.');
        }

        $blocking = TransferRequest::query()
            ->where('booking_id', $booking->getKey())
            ->get()
            ->contains(fn (TransferRequest $t) => $t->status->isBlockingActive() || $t->status === TransferRequestStatus::Draft);

        if ($blocking) {
            throw new DomainException('There is already an open transfer request on this booking.');
        }

        if ($type->movesOwnership()) {
            $newBuyerId = $data['new_buyer_id'] ?? null;
            if ($newBuyerId === null) {
                throw new DomainException('An incoming buyer is required for this transfer type.');
            }
        }

        return $this->transaction(function () use ($booking, $type, $data, $actor): TransferRequest {
            $this->ownership->ensureAllotment($booking, $actor);
            $currentPrimary = $this->ownership->currentOwners($booking)->firstWhere('is_primary', true)
                ?? $this->ownership->currentOwners($booking)->first();

            if (($data['new_buyer_id'] ?? null) !== null && $currentPrimary?->buyer_id === (int) $data['new_buyer_id']) {
                throw new DomainException('The incoming buyer already owns this plot.');
            }

            $transfer = TransferRequest::create([
                'request_number' => TransferRequest::formatCode($this->sequences->next(TransferRequest::SEQUENCE_KEY)),
                'booking_id' => $booking->id,
                'plot_id' => $booking->plot_id,
                'transfer_type' => $type,
                'status' => TransferRequestStatus::Draft,
                'current_buyer_id' => $currentPrimary?->buyer_id,
                'new_buyer_id' => $data['new_buyer_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'requested_at' => now(),
                'requested_by' => $actor->id,
                'created_by' => $actor->id,
            ]);

            PossessionTimeline::record(
                PossessionActivityType::TransferRequested,
                "Transfer {$transfer->request_number} raised ({$type->label()}).",
                $booking, $booking->plot, null,
                ['transfer_request_id' => $transfer->id, 'type' => $type->value],
                $actor,
            );

            Log::info('transfer_request.created', ['transfer_request_id' => $transfer->id, 'booking_id' => $booking->id, 'by' => $actor->id]);

            return $transfer;
        });
    }
}
