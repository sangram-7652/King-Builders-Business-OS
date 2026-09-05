<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Actions\Customers\UpdateCustomerProfile;
use App\Livewire\Portal\Concerns\InteractsWithCustomer;
use App\Models\Masters\City;
use App\Models\Masters\State;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.portal')]
#[Title('My profile')]
class Profile extends Component
{
    use InteractsWithCustomer;

    public bool $editing = false;

    public string $phone = '';

    public string $alternate_phone = '';

    public string $address = '';

    public string $state_id = '';

    public string $city_id = '';

    public string $pincode = '';

    public string $occupation = '';

    public function mount(): void
    {
        $this->fillFromCustomer();
    }

    private function fillFromCustomer(): void
    {
        $c = $this->customer();
        $this->phone = (string) $c->phone;
        $this->alternate_phone = (string) $c->alternate_phone;
        $this->address = (string) $c->address;
        $this->state_id = (string) ($c->state_id ?? '');
        $this->city_id = (string) ($c->city_id ?? '');
        $this->pincode = (string) $c->pincode;
        $this->occupation = (string) $c->occupation;
    }

    public function edit(): void
    {
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetValidation();
        $this->fillFromCustomer();
    }

    public function save(): void
    {
        $data = $this->validate([
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'alternate_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'state_id' => ['nullable', Rule::exists('states', 'id')],
            'city_id' => ['nullable', Rule::exists('cities', 'id')],
            'pincode' => ['nullable', 'string', 'max:12'],
            'occupation' => ['nullable', 'string', 'max:120'],
        ]);

        if ($data['city_id'] !== '' && $data['state_id'] !== ''
            && ! City::query()->whereKey($data['city_id'])->where('state_id', $data['state_id'])->exists()) {
            $this->addError('city_id', 'The selected city does not belong to the selected state.');

            return;
        }

        app(UpdateCustomerProfile::class)->handle($this->customer(), $data);

        $this->editing = false;
        $this->dispatch('toast', message: 'Your details have been updated.', variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.portal.profile', [
            'customer' => $this->customer()->fresh(['state', 'city']),
            'states' => State::query()->orderBy('name')->pluck('name', 'id'),
            'cities' => $this->state_id !== ''
                ? City::query()->where('state_id', $this->state_id)->orderBy('name')->pluck('name', 'id')
                : collect(),
        ]);
    }
}
