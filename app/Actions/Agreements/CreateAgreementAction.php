<?php

declare(strict_types=1);

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Enums\AgreementType;
use App\Enums\DocumentActivityType;
use App\Exceptions\DomainException;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Creates a DRAFT agreement for a CONFIRMED booking (M9). `agreement_number`
 * (AGR-000001) is concurrency-safe via the shared sequence.
 */
class CreateAgreementAction
{
    use RunsInTransaction;

    public function __construct(private readonly SequenceGenerator $sequences) {}

    public function handle(Booking $booking, AgreementType $type, User $actor, ?string $notes = null): Agreement
    {
        if (! $booking->isConfirmed()) {
            throw new DomainException('An agreement can only be created for a confirmed booking.');
        }

        return $this->transaction(function () use ($booking, $type, $actor, $notes): Agreement {
            $agreement = Agreement::create([
                'agreement_number' => Agreement::formatCode($this->sequences->next(Agreement::SEQUENCE_KEY)),
                'booking_id' => $booking->id,
                'type' => $type,
                'status' => AgreementStatus::Draft,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);

            DocumentTimeline::record(
                DocumentActivityType::AgreementCreated,
                "Agreement {$agreement->agreement_number} created ({$type->label()}).",
                $booking, null, ['agreement_id' => $agreement->id], $actor,
            );

            Log::info('agreement.created', ['agreement_id' => $agreement->id, 'booking_id' => $booking->id, 'by' => $actor->id]);

            return $agreement;
        });
    }
}
