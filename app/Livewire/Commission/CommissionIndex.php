<?php

declare(strict_types=1);

namespace App\Livewire\Commission;

use App\Enums\CommissionCaseStatus;
use App\Models\CommissionCase;
use App\Models\Partner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The commission worklist (M14.5) — `/commissions`. Every case, filterable by
 * status and partner. The dashboard totals arrive in M14.6.
 */
#[Layout('components.layouts.app')]
#[Title('Commissions')]
class CommissionIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $partner = '';

    public function mount(): void
    {
        $this->authorize('viewAny', CommissionCase::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'partner'], true)) {
            $this->resetPage();
        }
    }

    /** @return Builder<CommissionCase> */
    protected function query(): Builder
    {
        return CommissionCase::query()
            ->with(['partner:id,name,company_name,partner_code', 'booking:id,booking_number', 'scheme:id,code,version'])
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->partner !== '', fn (Builder $q) => $q->where('partner_id', (int) $this->partner))
            ->orderByDesc('id');
    }

    public function render(): View
    {
        $summary = CommissionCase::query()
            ->selectRaw('status, count(*) as n, coalesce(sum(commission_amount),0) as amount, coalesce(sum(paid_amount),0) as paid')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return view('livewire.commission.commission-index', [
            'cases' => $this->query()->paginate(25),
            'statuses' => CommissionCaseStatus::options(),
            'partners' => Partner::query()->whereHas('commissionCases')->orderBy('name')->pluck('name', 'id'),
            'summary' => $summary,
        ]);
    }
}
