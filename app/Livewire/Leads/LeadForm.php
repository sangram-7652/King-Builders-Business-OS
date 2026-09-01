<?php

declare(strict_types=1);

namespace App\Livewire\Leads;

use App\Actions\Leads\CreateLead;
use App\Actions\Leads\UpdateLead;
use App\Models\Lead;
use App\Models\Masters\LeadSource;
use App\Models\User;
use App\Support\Leads\DuplicateFinder;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Lead')]
class LeadForm extends Component
{
    public ?Lead $lead = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $lead_source_id = '';

    public string $assigned_to = '';

    public string $notes = '';

    /** Set once the user has seen and dismissed the duplicate warning. */
    public bool $duplicatesAcknowledged = false;

    public function mount(?Lead $lead = null): void
    {
        if ($lead?->exists) {
            $this->authorize('update', $lead);
            $this->lead = $lead;
            $this->name = $lead->name;
            $this->phone = $lead->phone;
            $this->email = (string) $lead->email;
            $this->lead_source_id = (string) ($lead->lead_source_id ?? '');
            $this->assigned_to = (string) ($lead->assigned_to ?? '');
            $this->notes = (string) $lead->notes;
            $this->duplicatesAcknowledged = true;
        } else {
            $this->authorize('create', Lead::class);
        }
    }

    #[Computed]
    public function editing(): bool
    {
        return $this->lead !== null;
    }

    #[Computed]
    public function canAssign(): bool
    {
        return auth()->user()?->can('leads.assign') ?? false;
    }

    /**
     * Live duplicate detection — surfaced as a warning, never a block.
     */
    #[Computed]
    public function duplicates()
    {
        if (strlen(DuplicateFinder::phoneDigits($this->phone)) < 6 && $this->email === '') {
            return collect();
        }

        return app(DuplicateFinder::class)->leads($this->phone, $this->email, $this->lead?->id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'lead_source_id' => ['nullable', Rule::exists('lead_sources', 'id')->withoutTrashed()],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function save()
    {
        $data = $this->validate();

        if (! $this->canAssign) {
            $data['assigned_to'] = $this->lead?->assigned_to;
        }

        if ($this->editing) {
            app(UpdateLead::class)->handle($this->lead, $data);
            $lead = $this->lead;
        } else {
            $lead = app(CreateLead::class)->handle($data, auth()->user());
        }

        $this->dispatch('toast', message: $this->editing ? 'Lead updated.' : 'Lead created.', variant: 'success');

        $this->redirectRoute('leads.show', ['lead' => $lead->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.leads.lead-form', [
            'sources' => LeadSource::query()->active()->ordered()->pluck('name', 'id'),
            'users' => $this->canAssign
                ? User::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')
                : collect(),
        ]);
    }
}
