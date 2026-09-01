<?php

declare(strict_types=1);

namespace App\Livewire\Leads;

use App\Enums\LeadStatus;
use App\Exceptions\DomainException;
use App\Models\Lead;
use App\Models\Masters\LeadSource;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Leads')]
class LeadIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $assigned = '';

    #[Url]
    public string $followUp = '';

    #[Url]
    public string $sort = 'created_at';

    #[Url]
    public string $direction = 'desc';

    #[Url]
    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'source', 'assigned', 'followUp', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function sortBy(string $column): void
    {
        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'source', 'assigned', 'followUp');
        $this->resetPage();
    }

    public function delete(int $lead): void
    {
        $model = Lead::findOrFail($lead);
        $this->authorize('delete', $model);

        try {
            $model->delete();
            $this->dispatch('toast', message: 'Lead deleted.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    /**
     * @return Builder<Lead>
     */
    protected function query(): Builder
    {
        $sortable = ['created_at', 'name', 'status', 'follow_up_at'];
        $sort = in_array($this->sort, $sortable, true) ? $this->sort : 'created_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        return Lead::query()
            ->visibleTo(auth()->user())
            ->with(['source:id,name', 'assignedTo:id,name'])
            ->search($this->search)
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->source !== '', fn (Builder $q) => $q->where('lead_source_id', $this->source))
            ->when($this->assigned === 'me', fn (Builder $q) => $q->where('assigned_to', auth()->id()))
            ->when($this->assigned === 'unassigned', fn (Builder $q) => $q->whereNull('assigned_to'))
            ->when(is_numeric($this->assigned), fn (Builder $q) => $q->where('assigned_to', (int) $this->assigned))
            ->when($this->followUp === 'overdue', fn (Builder $q) => $q->whereNotNull('follow_up_at')->where('follow_up_at', '<', now()))
            ->when($this->followUp === 'today', fn (Builder $q) => $q->whereBetween('follow_up_at', [now()->startOfDay(), now()->endOfDay()]))
            ->when($this->followUp === 'upcoming', fn (Builder $q) => $q->whereNotNull('follow_up_at')->where('follow_up_at', '>=', now()))
            ->when($this->followUp === 'none', fn (Builder $q) => $q->whereNull('follow_up_at'))
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');
    }

    public function render(): View
    {
        return view('livewire.leads.lead-index', [
            'leads' => $this->query()->paginate($this->perPage),
            'statuses' => LeadStatus::options(),
            'sources' => LeadSource::query()->orderBy('name')->pluck('name', 'id'),
            'assignees' => auth()->user()->can('leads.view_all')
                ? User::query()->orderBy('name')->pluck('name', 'id')
                : collect(),
        ]);
    }
}
