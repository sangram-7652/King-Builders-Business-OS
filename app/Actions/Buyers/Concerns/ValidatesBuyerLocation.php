<?php

declare(strict_types=1);

namespace App\Actions\Buyers\Concerns;

use App\Exceptions\DomainException;
use App\Models\Masters\City;

trait ValidatesBuyerLocation
{
    protected function assertCityBelongsToState(mixed $stateId, mixed $cityId): void
    {
        if (! $cityId) {
            return;
        }

        if (! $stateId) {
            throw new DomainException('Select a state before choosing a city.');
        }

        if (! City::query()->whereKey($cityId)->where('state_id', $stateId)->exists()) {
            throw new DomainException('The selected city does not belong to the selected state.');
        }
    }
}
