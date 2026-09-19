<?php

declare(strict_types=1);

namespace App\Livewire\Buyers;

use App\Actions\Buyers\CreateBuyer;
use App\Actions\Buyers\UpdateBuyer;
use App\Enums\Gender;
use App\Exceptions\DomainException;
use App\Models\Buyer;
use App\Models\Masters\City;
use App\Models\Masters\State;
use App\Support\Buyers\DuplicateFinder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Buyer')]
class BuyerForm extends Component
{
    public ?Buyer $buyer = null;

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

    public bool $canEditDocuments = false;

    public function mount(?Buyer $buyer = null): void
    {
        $this->canEditDocuments = auth()->user()?->can('buyers.documents') ?? false;

        if ($buyer?->exists) {
            $this->authorize('update', $buyer);
            $this->buyer = $buyer;
            foreach (['first_name', 'middle_name', 'last_name', 'phone', 'alternate_phone', 'email', 'occupation', 'address', 'pincode'] as $f) {
                $this->{$f} = (string) $buyer->{$f};
            }
            $this->date_of_birth = $buyer->date_of_birth?->format('Y-m-d') ?? '';
            $this->gender = $buyer->gender?->value ?? '';
            $this->state_id = (string) ($buyer->state_id ?? '');
            $this->city_id = (string) ($buyer->city_id ?? '');
            // Sensitive values are never pre-loaded into the form.
        } else {
            $this->authorize('create', Buyer::class);
        }
    }

    #[Computed]
    public function editing(): bool
    {
        return $this->buyer !== null;
    }

    #[Computed]
    public function cities()
    {
        return $this->state_id === ''
            ? collect()
            : City::query()->where('state_id', $this->state_id)->orderBy('name')->pluck('name', 'id');
    }

    #[Computed]
    public function duplicates()
    {
        if (strlen(DuplicateFinder::phoneDigits($this->phone)) < 6 && $this->email === '') {
            return collect();
        }

        return app(DuplicateFinder::class)->buyers($this->phone, $this->email)
            ->reject(fn (Buyer $b) => $this->buyer && $b->is($this->buyer))
            ->values();
    }

    public function updatedStateId(): void
    {
        $this->city_id = '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'alternate_phone' => ['nullable', 'string', 'max:20'],
            'email' => [
                'nullable', 'email', 'max:255',
                // Create only (F-BUY-1) — matches the buyers_email_canonical_unique
                // constraint exactly: soft-deleted buyers never block re-use, since
                // email_canonical is generated as NULL for them. Editing keeps its
                // existing behaviour unchanged; see BuyerForm::save() for the
                // defensive catch that still protects both paths from a raw 500.
                ...($this->editing ? [] : [Rule::unique('buyers', 'email_canonical')]),
            ],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'occupation' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'state_id' => ['nullable', Rule::exists('states', 'id')->withoutTrashed()],
            'city_id' => ['nullable', Rule::exists('cities', 'id')->where('state_id', $this->state_id ?: null)->withoutTrashed()],
            'pincode' => ['nullable', 'string', 'max:12'],
        ];

        if ($this->canEditDocuments) {
            $rules['pan_number'] = ['nullable', 'string', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'];
            $rules['aadhaar_number'] = ['nullable', 'string', 'regex:/^\d{4}\s?\d{4}\s?\d{4}$/'];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'city_id.exists' => 'The selected city does not belong to the selected state.',
            'pan_number.regex' => 'Enter a valid PAN (AAAAA9999A).',
            'aadhaar_number.regex' => 'Enter a valid 12-digit Aadhaar number.',
        ];
    }

    public function save()
    {
        // Canonicalise before validating — the same trim+lowercase logic the
        // `email` column mutator applies on save, so the uniqueness check
        // above compares like-for-like with what actually gets stored.
        $this->email = Buyer::normalizeEmail($this->email) ?? '';

        $data = $this->validate();

        if (! $this->canEditDocuments) {
            unset($data['pan_number'], $data['aadhaar_number']);
        }

        try {
            $buyer = $this->editing
                ? app(UpdateBuyer::class)->handle($this->buyer, $data)
                : app(CreateBuyer::class)->handle($data, auth()->user());
        } catch (DomainException $e) {
            $this->addError('city_id', $e->getMessage());

            return;
        } catch (UniqueConstraintViolationException $e) {
            // Defensive: two concurrent requests can both pass the pre-insert
            // uniqueness check above; the database constraint is the final
            // authority. Never let the raw SQLSTATE/query reach the user.
            // Match on the column name, not the MySQL constraint name — SQLite
            // (used in tests) reports "UNIQUE constraint failed: buyers.email_canonical"
            // rather than the named-key format MySQL uses.
            if (! str_contains($e->getMessage(), 'email_canonical')) {
                throw $e;
            }

            $this->addError('email', 'The email has already been taken.');

            return;
        }

        $this->dispatch('toast', message: $this->editing ? 'Buyer updated.' : "Buyer created — {$buyer->customer_code}.", variant: 'success');

        $this->redirectRoute('buyers.show', ['buyer' => $buyer->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.buyers.buyer-form', [
            'states' => State::query()->orderBy('name')->pluck('name', 'id'),
            'genders' => Gender::options(),
        ]);
    }
}
