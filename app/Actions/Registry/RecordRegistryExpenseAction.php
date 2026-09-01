<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Enums\DocumentActivityType;
use App\Enums\RegistryExpenseType;
use App\Exceptions\DomainException;
use App\Models\RegistryCase;
use App\Models\RegistryExpense;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Records a registry expense (M9). DECIMAL via {@see Money}, never float. This
 * is expense TRACKING only — it is not posted to the M6/M7 booking financials
 * and there is no accounting ledger.
 */
class RecordRegistryExpenseAction
{
    use RunsInTransaction;

    /**
     * @param  array{expense_type: string, amount: mixed, paid_by?: string|null, paid_at?: string|null, reference?: string|null, notes?: string|null}  $data
     */
    public function handle(RegistryCase $case, array $data, User $actor): RegistryExpense
    {
        if (! $actor->can('registry_expenses.create')) {
            throw new DomainException('You are not authorised to record registry expenses.');
        }

        $type = RegistryExpenseType::tryFrom((string) ($data['expense_type'] ?? ''));

        if ($type === null) {
            throw new DomainException('A valid expense type is required.');
        }

        $amount = Money::of($data['amount'] ?? null);

        if (! $amount->isPositive()) {
            throw new DomainException('An expense amount must be greater than zero.');
        }

        return $this->transaction(function () use ($case, $data, $actor, $type, $amount): RegistryExpense {
            $case->loadMissing('booking');

            $expense = RegistryExpense::create([
                'registry_case_id' => $case->id,
                'booking_id' => $case->booking_id,
                'expense_type' => $type,
                'amount' => $amount->store(),
                'status' => RegistryExpense::STATUS_RECORDED,
                'paid_by' => $data['paid_by'] ?? null,
                'paid_at' => $data['paid_at'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            DocumentTimeline::record(
                DocumentActivityType::RegistryExpenseRecorded,
                "{$type->label()} of ₹{$amount->store()} recorded on {$case->case_number}.",
                $case->booking, null, ['registry_expense_id' => $expense->id, 'amount' => $amount->store()], $actor,
            );

            Log::info('registry_expense.recorded', ['registry_expense_id' => $expense->id, 'amount' => $expense->amount, 'by' => $actor->id]);

            return $expense;
        });
    }
}
