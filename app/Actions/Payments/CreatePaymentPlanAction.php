<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentPlanStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Services\Payments\PaymentPlanGenerator;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Creates a DRAFT payment plan + its installments for a CONFIRMED booking.
 *
 * The plan total must equal the booking's frozen `final_amount` (the M6 price
 * snapshot is never touched) unless `allows_variance` is set by an authorised
 * user. Installment amounts always reconcile EXACTLY with the plan total.
 */
class CreatePaymentPlanAction
{
    use RunsInTransaction;

    public function __construct(private readonly PaymentPlanGenerator $generator) {}

    /**
     * @param  array{name?: string, total_amount?: mixed, allows_variance?: bool, schedule: list<array<string, mixed>>}  $data
     */
    public function handle(Booking $booking, array $data, User $actor): PaymentPlan
    {
        if (! $booking->isConfirmed()) {
            throw new DomainException('A payment plan can only be created for a confirmed booking.');
        }

        $total = Money::of($data['total_amount'] ?? $booking->final_amount);
        $allowsVariance = (bool) ($data['allows_variance'] ?? false);

        if (! $allowsVariance && ! $total->equals(Money::of($booking->final_amount))) {
            throw new DomainException(
                "The plan total (₹{$total->store()}) must match the booking amount (₹{$booking->final_amount})."
            );
        }

        $specs = $this->generator->generate($total, $data['schedule']);

        return $this->transaction(function () use ($booking, $data, $actor, $total, $allowsVariance, $specs): PaymentPlan {
            if ($booking->paymentPlans()->whereIn('status', [PaymentPlanStatus::Draft->value, PaymentPlanStatus::Active->value])->exists()) {
                throw new DomainException('This booking already has a live payment plan.');
            }

            $plan = PaymentPlan::create([
                'booking_id' => $booking->id,
                'name' => trim((string) ($data['name'] ?? '')) ?: 'Payment plan',
                'total_amount' => $total->store(),
                'status' => PaymentPlanStatus::Draft,
                'allows_variance' => $allowsVariance,
                'created_by' => $actor->id,
            ]);

            foreach ($specs as $spec) {
                $plan->installments()->create($spec->toAttributes());
            }

            Log::info('payment_plan.created', [
                'payment_plan_id' => $plan->id,
                'booking_id' => $booking->id,
                'total' => $plan->total_amount,
                'installments' => count($specs),
                'by' => $actor->id,
            ]);

            return $plan->load('installments');
        });
    }
}
