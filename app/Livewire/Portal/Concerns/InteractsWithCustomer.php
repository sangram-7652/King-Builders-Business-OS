<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Concerns;

use App\Models\Buyer;

/**
 * Shared helper for authenticated portal Livewire components (M15). The
 * `customer` guard is the only source of the current customer — never a URL id.
 */
trait InteractsWithCustomer
{
    protected function customer(): Buyer
    {
        /** @var Buyer $buyer */
        $buyer = auth('customer')->user();

        return $buyer;
    }
}
