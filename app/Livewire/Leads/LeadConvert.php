<?php

declare(strict_types=1);

namespace App\Livewire\Leads;

use App\Actions\Leads\ConvertLeadToBuyer;
use App\Enums\Gender;
use App\Enums\LeadStatus;
use App\Exceptions\DomainException;
use App\Models\Lead;
use App\Models\Masters\City;
use App\Models\Masters\State;
use App\Support\Leads\DuplicateFinder;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class LeadConvert extends Component
{
    public Lead $lead;

    /** 'existing' | 'new' */
    public string $mode = 'new';

    public ?int $existingBuyerId = null;

    // New-buyer form
    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $phone = '';

    public string $alternate_phone = '';

    public string $email = '';

    public string $date_of_birth = '';

    public string $gender = '';

    public string $occupation = '';

    public string $address = '';

    public string $state_id = '';

    public string $city_id = '';

    public string $pincode = '';

    public string $pan_number = '';

    public string $aadhaar_number = '';

    public function mount(Lead $lead): void
    {
        $this->authorize('convert', $lead);
        $this->lead = $lead;

        // Prefill from the lead.
        [$first, $rest] = array_pad(explode(' ', trim($lead->name), 2), 2, '');
        $this->first_name = $first;
        $this->last_name = $rest;
        $this->phone = $lead->phone;
        $this->email = (string) $lead->email;

        if ($lead->isConverted()) {
            // Idempotent: nothing to do — bounce to the buyer.
            $this->redirectRoute('buyers.show', ['buyer' => $lead->buyer_id], navigate: true);
        }
    }

    #[Computed]
    public function buyerMatches()
    {
        return app(DuplicateFinder::class)->buyers($this->lead->phone, $this->lead->email);
    }

    #[Computed]
    public function cities()
    {
        return $this->state_id === ''
            ? collect()
            : City::query()->where('state_id', $this->state_id)->orderBy('name')->pluck('name', 'id');
    }

    public function updatedStateId(): void
    {
        $this->city_id = '';
    }

    public function useExisting(int $buyerId): void
    {
        $this->mode = 'existing';
        $this->existingBuyerId = $buyerId;
    }

    public function useNew(): void
    {
        $this->mode = 'new';
        $this->existingBuyerId = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function newBuyerRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'alternate_phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'occupation' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'state_id' => ['nullable', Rule::exists('states', 'id')->withoutTrashed()],
            'city_id' => ['nullable', Rule::exists('cities', 'id')->where('state_id', $this->state_id ?: null)->withoutTrashed()],
            'pincode' => ['nullable', 'string', 'max:12'],
            'pan_number' => ['nullable', 'string', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
            'aadhaar_number' => ['nullable', 'string', 'regex:/^\d{4}\s?\d{4}\s?\d{4}$/'],
        ];
    }

    protected function messages(): array
    {
        return [
            'city_id.exists' => 'The selected city does not belong to the selected state.',
            'pan_number.regex' => 'Enter a valid PAN (AAAAA9999A).',
            'aadhaar_number.regex' => 'Enter a valid 12-digit Aadhaar number.',
        ];
    }

    public function convert()
    {
        $this->authorize('convert', $this->lead);

        try {
            if ($this->mode === 'existing') {
                $this->validate(['existingBuyerId' => ['required', 'integer', 'exists:buyers,id']]);
                $buyer = app(ConvertLeadToBuyer::class)->handle(
                    $this->lead, auth()->user(), existingBuyerId: $this->existingBuyerId,
                );
            } else {
                $data = $this->validate($this->newBuyerRules());
                $buyer = app(ConvertLeadToBuyer::class)->handle(
                    $this->lead, auth()->user(), newBuyerData: $data,
                );
            }
        } catch (DomainException $e) {
            $this->addError('existingBuyerId', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: "Lead converted — buyer {$buyer->customer_code}.", variant: 'success');

        $this->redirectRoute('buyers.show', ['buyer' => $buyer->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.leads.lead-convert', [
            'states' => State::query()->orderBy('name')->pluck('name', 'id'),
            'genders' => Gender::options(),
            'notQualified' => $this->lead->status !== LeadStatus::Qualified && ! $this->lead->isConverted(),
        ])->title('Convert lead');
    }
}
