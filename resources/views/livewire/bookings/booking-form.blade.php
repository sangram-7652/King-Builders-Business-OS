<div class="space-y-6">
    <x-ui.breadcrumb :items="array_values(array_filter([
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        $booking ? ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)] : null,
        ['label' => $booking ? 'Edit' : 'New booking'],
    ]))" />

    <x-ui.page-header :title="$booking ? 'Edit '.$booking->booking_number : 'New booking'"
        description="Frontend figures are a preview — the server recalculates the price before saving and again on confirmation." />

    <form wire:submit="save" class="space-y-6">
        {{-- Plot selection --}}
        <x-ui.card title="Plot">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.select label="Project" wire:model.live="project_id" placeholder="Select…" :options="$projects->toArray()"
                    :error="$errors->first('project_id')" :disabled="(bool) $booking" />
                <x-ui.select label="Block" wire:model.live="block_id" placeholder="Select…" :options="$blocks->toArray()"
                    :error="$errors->first('block_id')" :disabled="(bool) $booking" />
                <x-ui.select label="Plot" wire:model.live="plot_id" placeholder="Select…" :options="$plots->toArray()"
                    :error="$errors->first('plot_id')" :disabled="(bool) $booking" />
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-ui.input type="date" label="Booking date" wire:model="booking_date" :error="$errors->first('booking_date')" />
            </div>
            <div class="mt-4">
                <x-ui.textarea label="Notes" wire:model="notes" rows="2" :error="$errors->first('notes')" />
            </div>
        </x-ui.card>

        {{-- Pricing --}}
        <x-ui.card title="Pricing" subtitle="Base + PLC + charges − discount + tax = final amount.">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input type="number" step="0.0001" label="Base area (sq ft)" wire:model.live.debounce.400ms="base_area" :error="$errors->first('base_area')" />
                <x-ui.input type="number" step="0.0001" label="Base rate (₹ / sq ft)" wire:model.live.debounce.400ms="base_rate" :error="$errors->first('base_rate')" />
            </div>

            @php
                $lineBlock = function ($label, $rows, $addMethod, $removeMethod, $masterKey, $masterOptions, $calcTypes) {
                    return compact('label', 'rows', 'addMethod', 'removeMethod', 'masterKey', 'masterOptions', 'calcTypes');
                };
            @endphp

            {{-- PLC --}}
            <div class="mt-6">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold">Preferential location charges</h4>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="addPlc">+ Add PLC</x-ui.button>
                </div>
                @foreach ($plcLines as $i => $row)
                    <div wire:key="plc-{{ $i }}" class="mt-2 grid items-end gap-2 sm:grid-cols-12">
                        <div class="sm:col-span-3"><x-ui.select label="PLC type" wire:model.live="plcLines.{{ $i }}.plc_type_id" placeholder="Custom…" :options="$plcTypes->toArray()" /></div>
                        <div class="sm:col-span-3"><x-ui.input label="Name" wire:model.live.debounce.400ms="plcLines.{{ $i }}.name" /></div>
                        <div class="sm:col-span-3"><x-ui.select label="Calc" wire:model.live="plcLines.{{ $i }}.calculation_type" :options="$calcTypes" /></div>
                        <div class="sm:col-span-2"><x-ui.input type="number" step="0.0001" label="Rate" wire:model.live.debounce.400ms="plcLines.{{ $i }}.rate" /></div>
                        <div class="sm:col-span-1"><x-ui.button type="button" variant="ghost" size="sm" class="text-red-600" wire:click="removePlc({{ $i }})">✕</x-ui.button></div>
                    </div>
                @endforeach
            </div>

            {{-- Charges --}}
            <div class="mt-6">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold">Other charges</h4>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="addCharge">+ Add charge</x-ui.button>
                </div>
                @foreach ($chargeLines as $i => $row)
                    <div wire:key="charge-{{ $i }}" class="mt-2 grid items-end gap-2 sm:grid-cols-12">
                        <div class="sm:col-span-3"><x-ui.select label="Charge type" wire:model.live="chargeLines.{{ $i }}.charge_type_id" placeholder="Custom…" :options="$chargeTypes->toArray()" /></div>
                        <div class="sm:col-span-3"><x-ui.input label="Name" wire:model.live.debounce.400ms="chargeLines.{{ $i }}.name" /></div>
                        <div class="sm:col-span-3"><x-ui.select label="Calc" wire:model.live="chargeLines.{{ $i }}.calculation_type" :options="$calcTypes" /></div>
                        <div class="sm:col-span-2"><x-ui.input type="number" step="0.0001" label="Rate" wire:model.live.debounce.400ms="chargeLines.{{ $i }}.rate" /></div>
                        <div class="sm:col-span-1"><x-ui.button type="button" variant="ghost" size="sm" class="text-red-600" wire:click="removeCharge({{ $i }})">✕</x-ui.button></div>
                    </div>
                @endforeach
            </div>

            {{-- Discounts --}}
            <div class="mt-6">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold">Discounts</h4>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="addDiscount">+ Add discount</x-ui.button>
                </div>
                @foreach ($discountLines as $i => $row)
                    <div wire:key="discount-{{ $i }}" class="mt-2 grid items-end gap-2 sm:grid-cols-12">
                        <div class="sm:col-span-5"><x-ui.input label="Name" wire:model.live.debounce.400ms="discountLines.{{ $i }}.name" /></div>
                        <div class="sm:col-span-3"><x-ui.select label="Calc" wire:model.live="discountLines.{{ $i }}.calculation_type" :options="$calcTypes" /></div>
                        <div class="sm:col-span-3"><x-ui.input type="number" step="0.0001" label="Rate / %" wire:model.live.debounce.400ms="discountLines.{{ $i }}.rate" /></div>
                        <div class="sm:col-span-1"><x-ui.button type="button" variant="ghost" size="sm" class="text-red-600" wire:click="removeDiscount({{ $i }})">✕</x-ui.button></div>
                        <div class="sm:col-span-12"><x-ui.input label="Remark (printed on the receipt)" wire:model.live.debounce.400ms="discountLines.{{ $i }}.remark" /></div>
                    </div>
                @endforeach
            </div>

            {{-- Taxes --}}
            <div class="mt-6">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold">Taxes</h4>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="addTax">+ Add tax</x-ui.button>
                </div>
                @foreach ($taxLines as $i => $row)
                    <div wire:key="tax-{{ $i }}" class="mt-2 grid items-end gap-2 sm:grid-cols-12">
                        <div class="sm:col-span-4"><x-ui.select label="Tax rate" wire:model.live="taxLines.{{ $i }}.tax_rate_id" placeholder="Custom…" :options="$taxRates->toArray()" /></div>
                        <div class="sm:col-span-4"><x-ui.input label="Name" wire:model.live.debounce.400ms="taxLines.{{ $i }}.name" /></div>
                        <div class="sm:col-span-3"><x-ui.input type="number" step="0.0001" label="%" wire:model.live.debounce.400ms="taxLines.{{ $i }}.rate" /></div>
                        <div class="sm:col-span-1"><x-ui.button type="button" variant="ghost" size="sm" class="text-red-600" wire:click="removeTax({{ $i }})">✕</x-ui.button></div>
                    </div>
                @endforeach
            </div>

            {{-- Preview --}}
            <div class="mt-6 rounded-lg border border-(--border) bg-(--surface-muted) p-4">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold">Calculation preview</h4>
                    <x-ui.button type="button" variant="secondary" size="sm" wire:click="recalculate">Recalculate</x-ui.button>
                </div>

                @if ($previewError)
                    <p class="mt-3 text-sm text-red-600">{{ $previewError }}</p>
                @elseif ($preview)
                    <dl class="mt-3 space-y-1 text-sm">
                        <div class="flex justify-between"><dt class="text-(--content-muted)">Base ({{ $preview['base_area'] }} × ₹{{ $preview['base_rate'] }})</dt><dd class="tabular-nums">₹{{ number_format((float) $preview['base_amount'], 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-(--content-muted)">PLC</dt><dd class="tabular-nums">₹{{ number_format((float) $preview['plc_amount'], 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-(--content-muted)">Other charges</dt><dd class="tabular-nums">₹{{ number_format((float) $preview['charge_amount'], 2) }}</dd></div>
                        <div class="flex justify-between font-medium"><dt>Subtotal</dt><dd class="tabular-nums">₹{{ number_format((float) $preview['subtotal'], 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-(--content-muted)">Discount</dt><dd class="tabular-nums">−₹{{ number_format((float) $preview['discount_amount'], 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-(--content-muted)">Tax</dt><dd class="tabular-nums">₹{{ number_format((float) $preview['tax_amount'], 2) }}</dd></div>
                        <div class="flex justify-between border-t border-(--border) pt-1 text-base font-semibold"><dt>Final amount</dt><dd class="tabular-nums">₹{{ number_format((float) $preview['final_amount'], 2) }}</dd></div>
                    </dl>
                @endif
            </div>
        </x-ui.card>

        {{-- Buyers --}}
        <x-ui.card title="Buyers" subtitle="Select the buyer(s) for this booking.">
            @error('buyers') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @foreach ($buyers as $i => $row)
                <div wire:key="buyer-{{ $i }}" class="mt-2 grid items-end gap-2 sm:grid-cols-12">
                    <div class="sm:col-span-11"><x-ui.select label="Buyer" wire:model="buyers.{{ $i }}.buyer_id" placeholder="Select…" :options="$buyerOptions->toArray()"
                        :error="$errors->first('buyers.'.$i.'.buyer_id')" /></div>
                    <div class="sm:col-span-1"><x-ui.button type="button" variant="ghost" size="sm" class="text-red-600" wire:click="removeBuyer({{ $i }})">✕</x-ui.button></div>
                </div>
            @endforeach
            <x-ui.button type="button" variant="ghost" size="sm" class="mt-3" wire:click="addBuyer">+ Add buyer</x-ui.button>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button type="button" variant="secondary" :href="route('bookings.index')" wire:navigate>Cancel</x-ui.button>
            <x-ui.button type="submit">Save draft</x-ui.button>
            <x-ui.button type="button" wire:click="saveAndSubmit">Save &amp; submit</x-ui.button>
        </div>
    </form>
</div>
