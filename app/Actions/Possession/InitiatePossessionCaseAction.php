<?php

declare(strict_types=1);

namespace App\Actions\Possession;

use App\Enums\ClearanceCategory;
use App\Enums\ClearanceStatus;
use App\Enums\PossessionActivityType;
use App\Enums\PossessionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\PossessionCase;
use App\Models\User;
use App\Services\Ownership\PlotOwnershipService;
use App\Services\Possession\PossessionEligibilityService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Opens the possession case for a CONFIRMED booking (M10). One per booking
 * (unique `booking_id`), POS-000001 via the shared sequence — idempotent and
 * safe under a race. Runs the eligibility engine, seeds the clearance rows and
 * materialises the original allotment ownership.
 */
class InitiatePossessionCaseAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly PossessionEligibilityService $eligibility,
        private readonly PlotOwnershipService $ownership,
    ) {}

    public function handle(Booking $booking, User $actor): PossessionCase
    {
        if (! $actor->can('possession.create')) {
            throw new DomainException('You are not authorised to open a possession case.');
        }

        if (! $booking->isConfirmed()) {
            throw new DomainException('A possession case can only be opened for a confirmed booking.');
        }

        if (($existing = PossessionCase::query()->where('booking_id', $booking->getKey())->first()) !== null) {
            return $existing;
        }

        try {
            return $this->transaction(function () use ($booking, $actor): PossessionCase {
                $result = $this->eligibility->evaluate($booking);

                $case = PossessionCase::create([
                    'case_number' => PossessionCase::formatCode($this->sequences->next(PossessionCase::SEQUENCE_KEY)),
                    'booking_id' => $booking->id,
                    'plot_id' => $booking->plot_id,
                    'status' => $result->eligible ? PossessionCaseStatus::Ready : PossessionCaseStatus::EligibilityPending,
                    'eligibility_snapshot' => $result->toArray(),
                    'eligibility_checked_at' => now(),
                    'eligible_at' => $result->eligible ? now() : null,
                    'initiated_at' => now(),
                    'initiated_by' => $actor->id,
                    'created_by' => $actor->id,
                ]);

                foreach (config('possession.clearances') as $category => $conf) {
                    $case->clearances()->create([
                        'category' => ClearanceCategory::from($category),
                        'status' => ClearanceStatus::Pending,
                        'required' => (bool) ($conf['required'] ?? true),
                    ]);
                }

                $this->ownership->ensureAllotment($booking, $actor);

                PossessionTimeline::record(
                    PossessionActivityType::PossessionInitiated,
                    "Possession case {$case->case_number} opened — ".($result->eligible ? 'ready.' : 'eligibility pending.'),
                    $booking, $booking->plot, null,
                    ['possession_case_id' => $case->id, 'eligible' => $result->eligible],
                    $actor,
                );

                Log::info('possession_case.initiated', ['possession_case_id' => $case->id, 'booking_id' => $booking->id, 'by' => $actor->id]);

                return $case;
            });
        } catch (QueryException $e) {
            if (($existing = PossessionCase::query()->where('booking_id', $booking->getKey())->first()) !== null) {
                return $existing;
            }

            throw $e;
        }
    }
}
