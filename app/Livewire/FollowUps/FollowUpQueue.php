<?php

declare(strict_types=1);

namespace App\Livewire\FollowUps;

use App\Actions\Leads\CancelFollowUp;
use App\Actions\Leads\CompleteFollowUp;
use App\Actions\Leads\RescheduleFollowUp;
use App\Enums\FollowUpOutcome;
use App\Enums\FollowUpPriority;
use App\Enums\FollowUpType;
use App\Exceptions\DomainException;
use App\Models\LeadFollowUp;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The follow-up work queue (M13.1) — `/follow-ups`.
 *
 * Tabs: Today · Overdue · Upcoming · Missed · All. Scoped by
 * {@see LeadFollowUp::scopeVisibleTo()} — a salesperson sees only their own
 * unless they hold `leads.view_all`, in which case an assignee filter appears.
 * Complete / reschedule / cancel run the same actions as the lead screen.
 */
#[Layout('components.layouts.app')]
#[Title('Follow-ups')]
class FollowUpQueue extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'today';

    #[Url]
    public string $assignee = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $priority = '';

    // inline action state
    public ?int $completingId = null;

    public string $completeOutcome = '';

    public string $completeNote = '';

    public ?int $reschedulingId = null;

    public string $rescheduleDueAt = '';

    public function mount(): void
    {
        $this->authorize('viewAny', LeadFollowUp::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'assignee', 'type', 'priority'], true)) {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['today', 'overdue', 'upcoming', 'missed', 'all'], true) ? $tab : 'today';
        $this->resetPage();
    }

    // --- Complete ------------------------------------------------------

    public function startComplete(int $id): void
    {
        $this->reset('reschedulingId', 'rescheduleDueAt');
        $this->completingId = $id;
        $this->completeOutcome = '';
        $this->completeNote = '';
    }

    public function complete(): void
    {
        $followUp = $this->findOr403($this->completingId, 'complete');

        $data = $this->validate([
            'completeOutcome' => ['nullable', 'string'],
            'completeNote' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            app(CompleteFollowUp::class)->handle($followUp, [
                'outcome' => $data['completeOutcome'] ?: null,
                'note' => $data['completeNote'] ?: null,
            ], auth()->user());
            $this->dispatch('toast', message: 'Follow-up completed.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        $this->reset('completingId', 'completeOutcome', 'completeNote');
    }

    // --- Reschedule -------------------------------------------------

    public function startReschedule(int $id): void
    {
        $this->reset('completingId', 'completeOutcome', 'completeNote');
        $this->reschedulingId = $id;
        $this->rescheduleDueAt = now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i');
    }

    public function reschedule(): void
    {
        $followUp = $this->findOr403($this->reschedulingId, 'reschedule');

        $data = $this->validate(['rescheduleDueAt' => ['required', 'date']]);

        try {
            app(RescheduleFollowUp::class)->handle($followUp, ['due_at' => $data['rescheduleDueAt']], auth()->user());
            $this->dispatch('toast', message: 'Follow-up rescheduled.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        $this->reset('reschedulingId', 'rescheduleDueAt');
    }

    // --- Cancel ---------------------------------------------------

    public function cancel(int $id): void
    {
        $followUp = $this->findOr403($id, 'cancel');

        try {
            app(CancelFollowUp::class)->handle($followUp, auth()->user());
            $this->dispatch('toast', message: 'Follow-up cancelled.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    private function findOr403(?int $id, string $ability): LeadFollowUp
    {
        $followUp = LeadFollowUp::query()->with('lead')->findOrFail($id);
        $this->authorize($ability, $followUp);

        return $followUp;
    }

    /** @return Builder<LeadFollowUp> */
    protected function query(): Builder
    {
        $user = auth()->user();

        return LeadFollowUp::query()
            ->visibleTo($user)
            ->with(['lead:id,name,phone,status,assigned_to,created_by', 'assignee:id,name'])
            ->when($this->tab === 'today', fn (Builder $q) => $q->dueToday())
            ->when($this->tab === 'overdue', fn (Builder $q) => $q->overdue())
            ->when($this->tab === 'upcoming', fn (Builder $q) => $q->upcoming())
            ->when($this->tab === 'missed', fn (Builder $q) => $q->missed())
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->when($this->priority !== '', fn (Builder $q) => $q->where('priority', $this->priority))
            ->when(
                $this->assignee !== '' && $user->can('leads.view_all'),
                fn (Builder $q) => $this->assignee === 'unassigned'
                    ? $q->whereNull('assigned_to')
                    : $q->where('assigned_to', (int) $this->assignee),
            )
            ->orderBy('due_at', $this->tab === 'upcoming' ? 'asc' : 'desc')
            ->orderByDesc('id');
    }

    public function render(): View
    {
        $user = auth()->user();
        $canViewAll = $user->can('leads.view_all');

        $counts = [
            'today' => (clone $this->baseScoped())->dueToday()->count(),
            'overdue' => (clone $this->baseScoped())->overdue()->count(),
            'upcoming' => (clone $this->baseScoped())->upcoming()->count(),
            'missed' => (clone $this->baseScoped())->missed()->count(),
        ];

        return view('livewire.follow-ups.follow-up-queue', [
            'followUps' => $this->query()->paginate(20),
            'counts' => $counts,
            'types' => FollowUpType::options(),
            'priorities' => FollowUpPriority::options(),
            'outcomes' => FollowUpOutcome::options(),
            'assignees' => $canViewAll
                ? User::query()->orderBy('name')->pluck('name', 'id')
                : collect(),
            'canViewAll' => $canViewAll,
        ]);
    }

    /** @return Builder<LeadFollowUp> */
    private function baseScoped(): Builder
    {
        return LeadFollowUp::query()->visibleTo(auth()->user());
    }
}
