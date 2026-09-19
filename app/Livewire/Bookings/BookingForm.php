<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Bookings\CalculateBookingPriceAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\SubmitBookingAction;
use App\Actions\Bookings\UpdateBookingAction;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Enums\PriceCalculationType;
use App\Enums\PriceComponentType;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Masters\ChargeType;
use App\Models\Masters\PlcType;
use App\Models\Masters\TaxRate;
use App\Models\Plot;
use App\Models\Project;
use App\Support\Pricing\PriceBreakdown;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Booking')]
class BookingForm extends Component
{
    public ?Booking $booking = null;

    public string $project_id = '';

    public string $block_id = '';

    public string $plot_id = '';

    public string $booking_date = '';

    public string $notes = '';

    public string $base_area = '';

    public string $base_rate = '';

    /** @var array<int, array<string, mixed>> */
    public array $plcLines = [];

    /** @var array<int, array<string, mixed>> */
    public array $chargeLines = [];

    /** @var array<int, array<string, mixed>> */
    public array $discountLines = [];

    /** @var array<int, array<string, mixed>> */
    public array $taxLines = [];

    /** @var array<int, array<string, mixed>> */
    public array $buyers = [];

    public ?array $preview = null;

    public ?string $previewError = null;

    public function mount(?Booking $booking = null): void
    {
        $this->booking_date = now()->toDateString();

        if ($booking?->exists) {
            $this->authorize('update', $booking);
            $booking->load(['bookingBuyers', 'priceLines']);
            $this->booking = $booking;
            $this->hydrateFromBooking($booking);
        } else {
            $this->authorize('create', Booking::class);
            $this->buyers = [['buyer_id' => '', 'ownership_percentage' => '100', 'is_primary' => true]];
        }

        $this->recalculate();
    }

    private function hydrateFromBooking(Booking $booking): void
    {
        $this->project_id = (string) $booking->project_id;
        $this->block_id = (string) $booking->block_id;
        $this->plot_id = (string) $booking->plot_id;
        $this->booking_date = $booking->booking_date->toDateString();
        $this->notes = (string) $booking->notes;
        $this->base_area = (string) $booking->base_area;
        $this->base_rate = (string) $booking->base_rate;

        foreach ($booking->priceLines as $line) {
            $row = [
                'name' => $line->name,
                'calculation_type' => $line->calculation_type->value,
                'rate' => (string) $line->rate,
                'override' => (bool) data_get($line->metadata, 'override', false),
            ];

            match ($line->type) {
                PriceComponentType::Plc => $this->plcLines[] = $row + ['plc_type_id' => (string) ($line->plc_type_id ?? '')],
                PriceComponentType::Charge => $this->chargeLines[] = $row + ['charge_type_id' => (string) ($line->charge_type_id ?? '')],
                PriceComponentType::Discount => $this->discountLines[] = $row + ['remark' => (string) data_get($line->metadata, 'remark', '')],
                PriceComponentType::Tax => $this->taxLines[] = $row + ['tax_rate_id' => (string) ($line->tax_rate_id ?? '')],
                default => null,
            };
        }

        $this->buyers = $booking->bookingBuyers->map(fn ($bb) => [
            'buyer_id' => (string) $bb->buyer_id,
            'ownership_percentage' => (string) $bb->ownership_percentage,
            'is_primary' => (bool) $bb->is_primary,
        ])->all();
    }

    public function updatedPlotId(): void
    {
        $plot = Plot::find($this->plot_id);

        if ($plot && $this->base_area === '') {
            $this->base_area = (string) $plot->area;
        }

        $this->recalculate();
    }

    // --- Line management ------------------------------------------------

    public function addPlc(): void
    {
        $this->plcLines[] = ['plc_type_id' => '', 'name' => '', 'calculation_type' => PriceCalculationType::Fixed->value, 'rate' => '', 'override' => false];
    }

    public function addCharge(): void
    {
        $this->chargeLines[] = ['charge_type_id' => '', 'name' => '', 'calculation_type' => PriceCalculationType::Fixed->value, 'rate' => '', 'override' => false];
    }

    public function addDiscount(): void
    {
        $this->discountLines[] = ['name' => 'Discount', 'calculation_type' => PriceCalculationType::Fixed->value, 'rate' => '', 'override' => false, 'remark' => ''];
    }

    public function addTax(): void
    {
        $this->taxLines[] = ['tax_rate_id' => '', 'name' => '', 'calculation_type' => PriceCalculationType::Percentage->value, 'rate' => '', 'override' => false];
    }

    public function removePlc(int $i): void
    {
        unset($this->plcLines[$i]);
        $this->plcLines = array_values($this->plcLines);
        $this->recalculate();
    }

    public function removeCharge(int $i): void
    {
        unset($this->chargeLines[$i]);
        $this->chargeLines = array_values($this->chargeLines);
        $this->recalculate();
    }

    public function removeDiscount(int $i): void
    {
        unset($this->discountLines[$i]);
        $this->discountLines = array_values($this->discountLines);
        $this->recalculate();
    }

    public function removeTax(int $i): void
    {
        unset($this->taxLines[$i]);
        $this->taxLines = array_values($this->taxLines);
        $this->recalculate();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'plcLines.') && str_contains($property, '.plc_type_id')) {
            $this->applyMaster($property, PlcType::class, 'plcLines');
        }

        if (str_starts_with($property, 'chargeLines.') && str_contains($property, '.charge_type_id')) {
            $this->applyMaster($property, ChargeType::class, 'chargeLines');
        }

        if (str_starts_with($property, 'taxLines.') && str_contains($property, '.tax_rate_id')) {
            $this->applyTaxMaster($property);
        }

        if ($property === 'buyers') {
            return;
        }

        $this->recalculate();
    }

    private function applyMaster(string $property, string $modelClass, string $bag): void
    {
        [$_, $i] = explode('.', $property);
        $master = $modelClass::find($this->{$bag}[$i][$modelClass === PlcType::class ? 'plc_type_id' : 'charge_type_id'] ?? null);

        if ($master) {
            $this->{$bag}[$i]['name'] = $master->name;
            $this->{$bag}[$i]['calculation_type'] = $master->calculation_type->value;
            $this->{$bag}[$i]['rate'] = (string) $master->value;
        }
    }

    private function applyTaxMaster(string $property): void
    {
        [$_, $i] = explode('.', $property);
        $master = TaxRate::find($this->taxLines[$i]['tax_rate_id'] ?? null);

        if ($master) {
            $this->taxLines[$i]['name'] = $master->name;
            $this->taxLines[$i]['calculation_type'] = PriceCalculationType::Percentage->value;
            $this->taxLines[$i]['rate'] = (string) $master->percentage;
        }
    }

    // --- Pricing config + preview -------------------------------------

    /**
     * @return array{base_area: string, base_rate: string, components: array<int, array<string, mixed>>}
     */
    private function buildPricingConfig(): array
    {
        $components = [];

        foreach ($this->plcLines as $row) {
            $components[] = $this->componentRow(PriceComponentType::Plc, $row, ['plc_type_id' => $row['plc_type_id'] ?: null]);
        }
        foreach ($this->chargeLines as $row) {
            $components[] = $this->componentRow(PriceComponentType::Charge, $row, ['charge_type_id' => $row['charge_type_id'] ?: null]);
        }
        foreach ($this->discountLines as $row) {
            $remark = trim((string) ($row['remark'] ?? ''));
            $components[] = $this->componentRow(PriceComponentType::Discount, $row, [
                'metadata' => $remark !== '' ? ['remark' => $remark] : [],
            ]);
        }
        foreach ($this->taxLines as $row) {
            $components[] = $this->componentRow(PriceComponentType::Tax, $row, ['tax_rate_id' => $row['tax_rate_id'] ?: null]);
        }

        return [
            'base_area' => $this->base_area ?: '0',
            'base_rate' => $this->base_rate ?: '0',
            'components' => $components,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $refs
     * @return array<string, mixed>
     */
    private function componentRow(PriceComponentType $type, array $row, array $refs): array
    {
        return array_merge([
            'type' => $type->value,
            'name' => $row['name'] ?: $type->label(),
            'calculation_type' => $row['calculation_type'] ?? 'fixed',
            'rate' => (string) ($row['rate'] ?? '0'),
            'quantity' => null,
        ], $refs);
    }

    public function recalculate(): void
    {
        $this->previewError = null;

        try {
            /** @var PriceBreakdown $breakdown */
            $breakdown = app(CalculateBookingPriceAction::class)->handle($this->buildPricingConfig());
            $this->preview = $breakdown->toArray();
        } catch (DomainException $e) {
            $this->preview = null;
            $this->previewError = $e->getMessage();
        }
    }

    // --- Buyers -----------------------------------------------------

    public function addBuyer(): void
    {
        $this->buyers[] = ['buyer_id' => '', 'ownership_percentage' => '0', 'is_primary' => false];
        $this->redistributeOwnership();
    }

    public function removeBuyer(int $i): void
    {
        unset($this->buyers[$i]);
        $this->buyers = array_values($this->buyers);
        $this->redistributeOwnership();
    }

    /**
     * The Buyers section no longer exposes ownership % / primary controls —
     * co-ownership is still fully supported internally (booking_buyers,
     * BookingBuyerValidator, primary-buyer lookups elsewhere), so whenever the
     * buyer list itself changes shape we split the share evenly and default
     * the first buyer to primary, invisibly, satisfying the backend's "exactly
     * one primary / shares total exactly 100%" rule without a visible control.
     * An existing booking's already-stored shares are left untouched unless
     * the buyer list is actually edited (see hydrateFromBooking()).
     */
    private function redistributeOwnership(): void
    {
        $count = count($this->buyers);

        if ($count === 0) {
            return;
        }

        // Hundredths of a percent so the shares always sum to exactly 100.00
        // regardless of how many buyers there are.
        $share = intdiv(10000, $count);
        $remainder = 10000 - $share * $count;

        $hasPrimary = collect($this->buyers)->contains(fn ($row) => ($row['is_primary'] ?? false) === true);

        foreach (array_keys($this->buyers) as $position => $key) {
            $hundredths = $share + ($position === $count - 1 ? $remainder : 0);
            $this->buyers[$key]['ownership_percentage'] = number_format($hundredths / 100, 2, '.', '');
        }

        if (! $hasPrimary) {
            $firstKey = array_key_first($this->buyers);
            $this->buyers[$firstKey]['is_primary'] = true;
        }
    }

    // --- Persistence ------------------------------------------------

    protected function rules(): array
    {
        return [
            'project_id' => ['required', 'exists:projects,id'],
            'block_id' => ['required', 'exists:blocks,id'],
            'plot_id' => ['required', 'exists:plots,id'],
            'booking_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'base_area' => ['required', 'numeric', 'min:0'],
            'base_rate' => ['required', 'numeric', 'min:0'],
            'buyers' => ['required', 'array', 'min:1'],
            'buyers.*.buyer_id' => ['required', 'exists:buyers,id'],
            'buyers.*.ownership_percentage' => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }

    private function payload(): array
    {
        return [
            'project_id' => (int) $this->project_id,
            'block_id' => (int) $this->block_id,
            'plot_id' => (int) $this->plot_id,
            'booking_date' => $this->booking_date,
            'notes' => $this->notes ?: null,
            'pricing' => $this->buildPricingConfig(),
            'buyers' => array_map(fn ($b) => [
                'buyer_id' => (int) $b['buyer_id'],
                'ownership_percentage' => (string) $b['ownership_percentage'],
                'is_primary' => (bool) ($b['is_primary'] ?? false),
            ], $this->buyers),
        ];
    }

    public function save(bool $submit = false): void
    {
        $this->validate();
        $this->recalculate();

        if ($this->previewError !== null) {
            $this->dispatch('toast', message: $this->previewError, variant: 'danger');

            return;
        }

        try {
            if ($this->booking) {
                $this->authorize('update', $this->booking);
                $booking = app(UpdateBookingAction::class)->handle($this->booking, $this->payload(), auth()->user());
            } else {
                $this->authorize('create', Booking::class);
                $booking = app(CreateBookingAction::class)->handle(
                    $this->payload() + ['status' => BookingStatus::Draft->value],
                    auth()->user(),
                );
            }

            if ($submit && $booking->isDraft()) {
                app(SubmitBookingAction::class)->handle($booking, auth()->user());
            }

            $this->dispatch('toast', message: 'Booking saved.', variant: 'success');
            $this->redirectRoute('bookings.show', ['booking' => $booking->id], navigate: true);
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function saveAndSubmit(): void
    {
        $this->save(submit: true);
    }

    public function render(): View
    {
        $plotQuery = Plot::query()
            ->where(function ($q) {
                $q->where('is_active', true)
                    ->whereIn('status', [PlotStatus::Available->value, PlotStatus::Hold->value])
                    ->when($this->block_id !== '', fn ($q) => $q->where('block_id', $this->block_id));
            })
            // On edit the plot is fixed — make sure it stays selectable.
            ->when($this->booking, fn ($q) => $q->orWhere('id', $this->booking->plot_id));

        return view('livewire.bookings.booking-form', [
            'projects' => Project::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'blocks' => $this->project_id !== ''
                ? Block::query()->where('project_id', $this->project_id)->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                : collect(),
            'plots' => $plotQuery->orderBy('plot_number')->get(['id', 'plot_number', 'area', 'area_unit', 'status'])
                ->mapWithKeys(fn ($p) => [$p->id => "Plot {$p->plot_number} ({$p->status->label()}, {$p->areaLabel()})"]),
            'plcTypes' => PlcType::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'),
            'chargeTypes' => ChargeType::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'),
            'taxRates' => TaxRate::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'),
            'buyerOptions' => Buyer::query()->where('status', '!=', 'archived')->orderBy('first_name')
                ->get(['id', 'customer_code', 'first_name', 'middle_name', 'last_name'])
                ->mapWithKeys(fn ($b) => [$b->id => "{$b->fullName()} ({$b->customer_code})"]),
            'calcTypes' => PriceCalculationType::options(),
        ]);
    }
}
