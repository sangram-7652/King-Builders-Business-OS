<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Enums\DocumentActivityType;
use App\Enums\RegistryCaseStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\RegistryCase;
use App\Models\User;
use App\Services\Registry\RegistryEligibilityService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Opens the registry case for a CONFIRMED booking (M9). One per booking (unique
 * `booking_id`), REG-000001 via the shared sequence — idempotent and safe under
 * a race. Runs the eligibility engine and sets the initial status
 * (READY vs ELIGIBILITY_PENDING).
 */
class InitiateRegistryCaseAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly RegistryEligibilityService $eligibility,
    ) {}

    public function handle(Booking $booking, User $actor): RegistryCase
    {
        if (! $booking->isConfirmed()) {
            throw new DomainException('A registry case can only be opened for a confirmed booking.');
        }

        if (($existing = RegistryCase::query()->where('booking_id', $booking->getKey())->first()) !== null) {
            return $existing;
        }

        try {
            return $this->transaction(function () use ($booking, $actor): RegistryCase {
                $result = $this->eligibility->evaluate($booking);

                $case = RegistryCase::create([
                    'case_number' => RegistryCase::formatCode($this->sequences->next(RegistryCase::SEQUENCE_KEY)),
                    'booking_id' => $booking->id,
                    'status' => $result->eligible ? RegistryCaseStatus::Ready : RegistryCaseStatus::EligibilityPending,
                    'eligibility_snapshot' => $result->toArray(),
                    'eligibility_checked_at' => now(),
                    'initiated_at' => now(),
                    'initiated_by' => $actor->id,
                    'created_by' => $actor->id,
                ]);

                DocumentTimeline::record(
                    DocumentActivityType::RegistryInitiated,
                    "Registry case {$case->case_number} opened — ".($result->eligible ? 'ready.' : 'eligibility pending.'),
                    $booking, null, ['registry_case_id' => $case->id, 'eligible' => $result->eligible], $actor,
                );

                Log::info('registry_case.initiated', ['registry_case_id' => $case->id, 'booking_id' => $booking->id, 'by' => $actor->id]);

                return $case;
            });
        } catch (QueryException $e) {
            if (($existing = RegistryCase::query()->where('booking_id', $booking->getKey())->first()) !== null) {
                return $existing;
            }

            throw $e;
        }
    }
}
