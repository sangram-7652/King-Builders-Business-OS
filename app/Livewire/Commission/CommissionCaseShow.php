<?php

declare(strict_types=1);

namespace App\Livewire\Commission;

use App\Actions\Commission\ApproveCommissionCase;
use App\Actions\Commission\CancelCommissionCase;
use App\Actions\Commission\HoldCommissionCase;
use App\Actions\Commission\RecalculateCommissionCase;
use App\Actions\Commission\RecordCommissionPayout;
use App\Actions\Commission\ResumeCommissionCase;
use App\Actions\Commission\ReverseCommissionCase;
use App\Actions\Commission\VoidCommissionPayout;
use App\Enums\CommissionPayoutMethod;
use App\Exceptions\DomainException;
use App\Models\CommissionCase;
use App\Models\CommissionPayout;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class CommissionCaseShow extends Component
{
    public CommissionCase $case;

    public string $reasonInput = '';

    public ?string $pendingAction = null; // hold | cancel | reverse

    // payout form
    public bool $showPayout = false;

    public string $payoutAmount = '';

    public string $payoutMethod = 'bank_transfer';

    public string $payoutDate = '';

    public string $payoutReference = '';

    public string $payoutNotes = '';

    public function mount(CommissionCase $case): void
    {
        $this->authorize('view', $case);
        $this->case = $case;
        $this->payoutDate = now()->toDateString();
    }

    private function refresh(): void
    {
        $this->case = $this->case->fresh();
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            $this->refresh();
            $this->reset('reasonInput', 'pendingAction', 'showPayout', 'payoutAmount', 'payoutReference', 'payoutNotes');
            $this->dispatch('toast', message: $ok, variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    // --- workflow ---------------------------------------------------

    public function recalculate(): void
    {
        $this->authorize('recalculate', $this->case);
        $this->run(fn () => app(RecalculateCommissionCase::class)->handle($this->case, auth()->user()), 'Recalculated.');
    }

    public function approve(): void
    {
        $this->authorize('approve', $this->case);
        $this->run(fn () => app(ApproveCommissionCase::class)->handle($this->case, auth()->user()), 'Commission approved.');
    }

    public function resume(): void
    {
        $this->authorize('resume', $this->case);
        $this->run(fn () => app(ResumeCommissionCase::class)->handle($this->case, auth()->user()), 'Resumed.');
    }

    public function startReason(string $action): void
    {
        $this->pendingAction = in_array($action, ['hold', 'cancel', 'reverse'], true) ? $action : null;
        $this->reasonInput = '';
    }

    public function submitReason(): void
    {
        $this->validate(['reasonInput' => ['required', 'string', 'min:3', 'max:255']]);
        $reason = $this->reasonInput;

        match ($this->pendingAction) {
            'hold' => $this->authorize('hold', $this->case),
            'cancel' => $this->authorize('cancel', $this->case),
            'reverse' => $this->authorize('reverse', $this->case),
            default => abort(400),
        };

        $this->run(fn () => match ($this->pendingAction) {
            'hold' => app(HoldCommissionCase::class)->handle($this->case, auth()->user(), $reason),
            'cancel' => app(CancelCommissionCase::class)->handle($this->case, auth()->user(), $reason),
            'reverse' => app(ReverseCommissionCase::class)->handle($this->case, auth()->user(), $reason),
        }, 'Done.');
    }

    // --- payouts --------------------------------------------------

    public function openPayout(): void
    {
        $this->authorize('recordPayout', $this->case);
        $this->showPayout = true;
        $this->payoutAmount = $this->case->outstandingAmount();
        $this->payoutDate = now()->toDateString();
    }

    public function recordPayout(): void
    {
        $this->authorize('recordPayout', $this->case);

        $data = $this->validate([
            'payoutAmount' => ['required', 'numeric', 'gt:0'],
            'payoutMethod' => ['required', Rule::enum(CommissionPayoutMethod::class)],
            'payoutDate' => ['required', 'date'],
            'payoutReference' => ['nullable', 'string', 'max:120'],
            'payoutNotes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->run(fn () => app(RecordCommissionPayout::class)->handle($this->case, [
            'amount' => $data['payoutAmount'],
            'method' => $data['payoutMethod'],
            'paid_on' => $data['payoutDate'],
            'reference' => $data['payoutReference'] ?: null,
            'notes' => $data['payoutNotes'] ?: null,
        ], auth()->user()), 'Payout recorded.');
    }

    public function voidPayout(int $payoutId): void
    {
        $payout = CommissionPayout::query()->where('commission_case_id', $this->case->id)->findOrFail($payoutId);
        $this->authorize('voidPayout', $this->case);

        $this->run(fn () => app(VoidCommissionPayout::class)->handle($payout, auth()->user(), 'Voided from the commission case screen'), 'Payout voided.');
    }

    public function render(): View
    {
        $case = $this->case->load([
            'booking:id,booking_number,status,final_amount,project_id',
            'partner:id,name,company_name,partner_code',
            'scheme:id,code,version,name',
            'currentCalculation.calculatedBy',
            'calculations.calculatedBy',
            'payouts.recordedBy',
            'approvedBy',
            'events.causer',
        ]);

        return view('livewire.commission.commission-case-show', [
            'case' => $case,
            'payoutMethods' => CommissionPayoutMethod::options(),
        ])->title($case->case_number);
    }
}
