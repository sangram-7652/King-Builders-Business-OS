<?php

declare(strict_types=1);

namespace App\Livewire\Leads;

use App\Actions\Leads\AssignLead;
use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Leads\CompleteFollowUp;
use App\Actions\Leads\ScheduleFollowUp;
use App\Enums\FollowUpOutcome;
use App\Enums\LeadStatus;
use App\Exceptions\DomainException;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class LeadShow extends Component
{
    public Lead $lead;

    public bool $showAssign = false;

    public string $assignTo = '';

    public bool $showFollowUp = false;

    public string $followUpDueAt = '';

    public string $followUpNote = '';

    public ?int $completingId = null;

    public string $completeOutcome = '';

    public string $completeNote = '';

    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);
        $this->lead = $lead;
    }

    private function refreshLead(): void
    {
        $this->lead = $this->lead->fresh();
    }

    // --- Status --------------------------------------------------------

    public function changeStatus(string $status): void
    {
        $this->authorize('changeStatus', $this->lead);

        $target = LeadStatus::tryFrom($status);
        if ($target === null) {
            return;
        }

        try {
            app(ChangeLeadStatus::class)->handle($this->lead, $target, auth()->user());
            $this->refreshLead();
            $this->dispatch('toast', message: "Status set to {$target->label()}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    // --- Assignment -------------------------------------------------

    public function openAssign(): void
    {
        $this->authorize('assign', $this->lead);
        $this->assignTo = (string) ($this->lead->assigned_to ?? '');
        $this->showAssign = true;
    }

    public function closeAssign(): void
    {
        $this->reset('showAssign', 'assignTo');
        $this->resetValidation();
    }

    public function assign(): void
    {
        $this->authorize('assign', $this->lead);
        $this->validate(['assignTo' => ['nullable', 'exists:users,id']]);

        try {
            $assignee = $this->assignTo !== '' ? User::find($this->assignTo) : null;
            app(AssignLead::class)->handle($this->lead, $assignee, auth()->user());
            $this->refreshLead();
            $this->dispatch('toast', message: 'Lead assignment updated.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        $this->closeAssign();
    }

    // --- Follow-ups ------------------------------------------------

    public function openFollowUp(): void
    {
        $this->authorize('followUp', $this->lead);
        $this->followUpDueAt = now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i');
        $this->followUpNote = '';
        $this->showFollowUp = true;
    }

    public function closeFollowUp(): void
    {
        $this->reset('showFollowUp', 'followUpDueAt', 'followUpNote');
        $this->resetValidation();
    }

    public function scheduleFollowUp(): void
    {
        $this->authorize('followUp', $this->lead);

        $data = $this->validate([
            'followUpDueAt' => ['required', 'date'],
            'followUpNote' => ['nullable', 'string', 'max:1000'],
        ]);

        app(ScheduleFollowUp::class)->handle($this->lead, [
            'due_at' => $data['followUpDueAt'],
            'note' => $data['followUpNote'] ?: null,
        ], auth()->user());

        $this->refreshLead();
        $this->dispatch('toast', message: 'Follow-up scheduled.', variant: 'success');
        $this->closeFollowUp();
    }

    public function openComplete(int $followUpId): void
    {
        $this->authorize('followUp', $this->lead);
        $this->completingId = $followUpId;
        $this->completeOutcome = '';
        $this->completeNote = '';
    }

    public function closeComplete(): void
    {
        $this->reset('completingId', 'completeOutcome', 'completeNote');
        $this->resetValidation();
    }

    public function completeFollowUp(): void
    {
        $this->authorize('followUp', $this->lead);

        $data = $this->validate([
            'completeOutcome' => ['required', 'string'],
            'completeNote' => ['nullable', 'string', 'max:1000'],
        ]);

        $followUp = LeadFollowUp::where('lead_id', $this->lead->id)->findOrFail($this->completingId);

        try {
            app(CompleteFollowUp::class)->handle($followUp, [
                'outcome' => $data['completeOutcome'],
                'note' => $data['completeNote'] ?: null,
            ], auth()->user());
            $this->refreshLead();
            $this->dispatch('toast', message: 'Follow-up completed.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        $this->closeComplete();
    }

    public function render(): View
    {
        $lead = $this->lead->load([
            'source', 'assignedTo', 'convertedBy', 'createdBy', 'buyer',
            'followUps.createdBy', 'activities.causer',
        ]);

        return view('livewire.leads.lead-show', [
            'lead' => $lead,
            'allowedTransitions' => $lead->status->allowedTransitions(),
            'assignableUsers' => auth()->user()->can('leads.assign')
                ? User::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')
                : collect(),
            'outcomes' => FollowUpOutcome::options(),
            'canConvert' => auth()->user()->can('convert', $lead),
        ])->title($lead->name);
    }
}
