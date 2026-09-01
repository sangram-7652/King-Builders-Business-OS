<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Enums\BuyerStatus;
use App\Exceptions\DomainException;
use App\Models\Buyer;
use Illuminate\Support\Facades\Log;

class ChangeBuyerStatus
{
    public function handle(Buyer $buyer, BuyerStatus $target): Buyer
    {
        $current = $buyer->status;

        if ($current === $target) {
            return $buyer;
        }

        if (! $current->canTransitionTo($target)) {
            throw new DomainException("A {$current->label()} buyer cannot move to {$target->label()}.");
        }

        $buyer->status = $target;
        $buyer->save();

        Log::info('buyer.status_changed', [
            'buyer_id' => $buyer->id,
            'from' => $current->value,
            'to' => $target->value,
            'by' => auth()->id(),
        ]);

        return $buyer;
    }
}
