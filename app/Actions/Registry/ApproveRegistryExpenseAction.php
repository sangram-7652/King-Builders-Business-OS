<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Enums\DocumentActivityType;
use App\Exceptions\DomainException;
use App\Models\RegistryExpense;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;

/**
 * RECORDED → APPROVED (M9). `registry_expenses.approve`. Idempotent. Approval
 * does NOT post anything to the booking financials.
 */
class ApproveRegistryExpenseAction
{
    use RunsInTransaction;

    public function handle(RegistryExpense $expense, User $actor): RegistryExpense
    {
        if (! $actor->can('registry_expenses.approve')) {
            throw new DomainException('You are not authorised to approve registry expenses.');
        }

        return $this->transaction(function () use ($expense, $actor): RegistryExpense {
            /** @var RegistryExpense $locked */
            $locked = RegistryExpense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('registryCase.booking');

            if ($locked->status === RegistryExpense::STATUS_APPROVED) {
                return $locked;
            }

            if ($locked->status !== RegistryExpense::STATUS_RECORDED) {
                throw new DomainException('Only a recorded expense can be approved.');
            }

            $locked->forceFill([
                'status' => RegistryExpense::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            DocumentTimeline::record(
                DocumentActivityType::RegistryExpenseApproved,
                "{$locked->expense_type->label()} of ₹{$locked->amount} approved.",
                $locked->registryCase->booking, null, ['registry_expense_id' => $locked->id], $actor,
            );

            return $locked;
        });
    }
}
