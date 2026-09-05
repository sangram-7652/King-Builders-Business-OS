<?php

declare(strict_types=1);

namespace App\Livewire\Commission;

use App\Actions\Commission\CreateCommissionScheme;
use App\Actions\Commission\UpdateCommissionScheme;
use App\Enums\CommissionBasis;
use App\Enums\PartnerType;
use App\Exceptions\DomainException;
use App\Models\CommissionScheme;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Commission scheme')]
class CommissionSchemeForm extends Component
{
    public ?CommissionScheme $scheme = null;

    public string $name = '';

    public string $description = '';

    public string $basis = 'booking_value';

    public string $partner_type = '';

    public bool $is_default = false;

    public string $effective_from = '';

    public string $effective_to = '';

    public function mount(?CommissionScheme $scheme = null): void
    {
        if ($scheme?->exists) {
            $this->authorize('update', $scheme);
            $this->scheme = $scheme;
            $this->name = $scheme->name;
            $this->description = (string) $scheme->description;
            $this->basis = $scheme->basis->value;
            $this->partner_type = $scheme->partner_type?->value ?? '';
            $this->is_default = $scheme->is_default;
            $this->effective_from = $scheme->effective_from?->toDateString() ?? '';
            $this->effective_to = $scheme->effective_to?->toDateString() ?? '';
        } else {
            $this->authorize('create', CommissionScheme::class);
            $this->basis = (string) config('commission.schemes.default_basis', 'booking_value');
        }
    }

    #[Computed]
    public function editing(): bool
    {
        return $this->scheme !== null;
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'basis' => ['required', Rule::enum(CommissionBasis::class)],
            'partner_type' => ['nullable', Rule::enum(PartnerType::class)],
            'is_default' => ['boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    public function save()
    {
        $data = $this->validate();

        try {
            $scheme = $this->editing
                ? app(UpdateCommissionScheme::class)->handle($this->scheme, $data, auth()->user())
                : app(CreateCommissionScheme::class)->handle($data, auth()->user());
        } catch (DomainException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: $this->editing ? 'Scheme updated.' : 'Scheme created.', variant: 'success');
        $this->redirectRoute('commission-schemes.show', ['scheme' => $scheme->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.commission.commission-scheme-form', [
            'bases' => CommissionBasis::options(),
            'partnerTypes' => PartnerType::options(),
        ]);
    }
}
