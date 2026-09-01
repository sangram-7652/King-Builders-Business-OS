<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Enums\AgingBucket;
use App\Enums\CollectionCaseStatus;
use App\Enums\CollectionPriority;
use App\Enums\PromiseStatus;
use App\Models\CollectionCase;
use App\Models\Project;
use App\Models\User;
use App\Services\Collections\AgingCalculator;
use App\Services\Payments\PaymentLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Collection queue')]
class CollectionQueue extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $owner = '';

    #[Url]
    public string $project = '';

    #[Url]
    public string $bucket = '';

    #[Url]
    public string $promise = '';

    public int $perPage = 20;

    public function mount(): void
    {
        $this->authorize('viewAny', CollectionCase::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'priority', 'status', 'owner', 'project', 'bucket', 'promise'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'priority', 'status', 'owner', 'project', 'bucket', 'promise');
        $this->resetPage();
    }

    /** @return Builder<CollectionCase> */
    protected function query(): Builder
    {
        return CollectionCase::query()
            ->visibleTo(auth()->user())
            ->with([
                'booking:id,booking_number,project_id,block_id,plot_id,final_amount',
                'booking.project:id,name', 'booking.plot:id,plot_number',
                'booking.primaryBookingBuyer.buyer:id,first_name,middle_name,last_name,phone',
                'assignedTo:id,name',
            ])
            ->when($this->priority !== '', fn (Builder $q) => $q->where('priority', $this->priority))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->status === '', fn (Builder $q) => $q->where('status', '!=', CollectionCaseStatus::Resolved->value))
            ->when($this->owner === 'me', fn (Builder $q) => $q->where('assigned_to', auth()->id()))
            ->when($this->owner === 'unassigned', fn (Builder $q) => $q->whereNull('assigned_to'))
            ->when(is_numeric($this->owner), fn (Builder $q) => $q->where('assigned_to', (int) $this->owner))
            ->when($this->project !== '', fn (Builder $q) => $q->whereHas('booking', fn (Builder $b) => $b->where('project_id', $this->project)))
            ->when($this->promise !== '', fn (Builder $q) => $q->whereHas('promises', fn (Builder $p) => $p->where('status', $this->promise)))
            ->when($this->search !== '', function (Builder $q) {
                $term = trim($this->search);
                $q->whereHas('booking', fn (Builder $b) => $b
                    ->where('booking_number', 'like', "%{$term}%")
                    ->orWhereHas('plot', fn (Builder $p) => $p->where('plot_number', 'like', "%{$term}%"))
                    ->orWhereHas('primaryBookingBuyer.buyer', fn (Builder $bu) => $bu
                        ->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")));
            })
            ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderBy('next_follow_up_at');
    }

    public function render(): View
    {
        $cases = $this->query()->paginate($this->perPage);
        $ledger = app(PaymentLedger::class);
        $aging = app(AgingCalculator::class);

        $rows = $cases->getCollection()->map(function (CollectionCase $case) use ($ledger, $aging) {
            $booking = $case->booking;

            return [
                'case' => $case,
                'outstanding' => $ledger->bookingOutstanding($booking)->clampToZero(),
                'overdue' => $ledger->bookingOverdue($booking),
                'days_overdue' => $aging->maxDaysOverdue($booking),
                'promise' => $case->promises->firstWhere('status', PromiseStatus::Open->value)
                    ?? $case->promises->first(),
            ];
        })->when($this->bucket !== '', fn ($c) => $c->filter(
            fn ($r) => AgingBucket::fromDaysOverdue($r['days_overdue'])?->value === $this->bucket
        ))->values();

        return view('livewire.collections.collection-queue', [
            'cases' => $cases,
            'rows' => $rows,
            'priorities' => CollectionPriority::options(),
            'statuses' => CollectionCaseStatus::options(),
            'buckets' => AgingBucket::options(),
            'promiseStatuses' => PromiseStatus::options(),
            'projects' => Project::query()->orderBy('name')->pluck('name', 'id'),
            'owners' => auth()->user()->can('collections.view_all')
                ? User::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')
                : collect(),
        ]);
    }
}
