<?php

declare(strict_types=1);

namespace App\Livewire\Partners;

use App\Actions\Partners\CreatePartnerAction;
use App\Actions\Partners\UpdatePartnerAction;
use App\Enums\PartnerType;
use App\Exceptions\DomainException;
use App\Models\Masters\City;
use App\Models\Masters\State;
use App\Models\Partner;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Channel Partner')]
class PartnerForm extends Component
{
    public ?Partner $partner = null;

    public string $type = 'individual';

    public string $name = '';

    public string $company_name = '';

    public string $contact_person = '';

    public string $phone = '';

    public string $alternate_phone = '';

    public string $email = '';

    public string $address = '';

    public string $state_id = '';

    public string $city_id = '';

    public string $pincode = '';

    public string $pan_number = '';

    public string $rera_number = '';

    public string $bank_account_name = '';

    public string $bank_account_number = '';

    public string $bank_ifsc = '';

    public string $bank_name = '';

    public string $notes = '';

    public function mount(?Partner $partner = null): void
    {
        if ($partner?->exists) {
            $this->authorize('update', $partner);
            $this->partner = $partner;
            $this->type = $partner->type->value;
            $this->name = $partner->name;
            $this->company_name = (string) $partner->company_name;
            $this->contact_person = (string) $partner->contact_person;
            $this->phone = $partner->phone;
            $this->alternate_phone = (string) $partner->alternate_phone;
            $this->email = (string) $partner->email;
            $this->address = (string) $partner->address;
            $this->state_id = (string) ($partner->state_id ?? '');
            $this->city_id = (string) ($partner->city_id ?? '');
            $this->pincode = (string) $partner->pincode;
            $this->pan_number = (string) $partner->pan_number;
            $this->rera_number = (string) $partner->rera_number;
            $this->bank_account_name = (string) $partner->bank_account_name;
            $this->bank_account_number = (string) $partner->bank_account_number;
            $this->bank_ifsc = (string) $partner->bank_ifsc;
            $this->bank_name = (string) $partner->bank_name;
            $this->notes = (string) $partner->notes;
        } else {
            $this->authorize('create', Partner::class);
        }
    }

    #[Computed]
    public function editing(): bool
    {
        return $this->partner !== null;
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PartnerType::class)],
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'alternate_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'state_id' => ['nullable', Rule::exists('states', 'id')],
            'city_id' => ['nullable', Rule::exists('cities', 'id')],
            'pincode' => ['nullable', 'string', 'max:12'],
            'pan_number' => ['nullable', 'string', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
            'rera_number' => ['nullable', 'string', 'max:120'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9\s]{6,40}$/'],
            'bank_ifsc' => ['nullable', 'string', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function save()
    {
        $data = $this->validate();

        try {
            $partner = $this->editing
                ? app(UpdatePartnerAction::class)->handle($this->partner, $data, auth()->user())
                : app(CreatePartnerAction::class)->handle($data, auth()->user());
        } catch (DomainException $e) {
            $this->addError('city_id', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: $this->editing ? 'Partner updated.' : 'Partner created.', variant: 'success');
        $this->redirectRoute('partners.show', ['partner' => $partner->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.partners.partner-form', [
            'types' => PartnerType::options(),
            'states' => State::query()->orderBy('name')->pluck('name', 'id'),
            'cities' => $this->state_id !== ''
                ? City::query()->where('state_id', $this->state_id)->orderBy('name')->pluck('name', 'id')
                : collect(),
        ]);
    }
}
