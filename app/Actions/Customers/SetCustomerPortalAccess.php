<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\CustomerActivityType;
use App\Enums\CustomerPortalStatus;
use App\Exceptions\DomainException;
use App\Models\Buyer;
use App\Models\CustomerInvitation;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Staff toggle for a customer's portal access (M15). SUSPEND signs the customer
 * out at the next request (via EnsureCustomerPortalActive) and spends any
 * outstanding token; RESTORE returns an active customer to ACTIVE, or a
 * never-activated one to INVITED so they can be re-invited.
 */
class SetCustomerPortalAccess
{
    use RunsInTransaction;

    public function suspend(Buyer $buyer, User $actor, ?string $reason = null): Buyer
    {
        if (! in_array($buyer->portal_status, [CustomerPortalStatus::Active, CustomerPortalStatus::Invited], true)) {
            throw new DomainException('This customer has no portal access to suspend.');
        }

        return $this->transaction(function () use ($buyer, $actor, $reason): Buyer {
            /** @var Buyer $locked */
            $locked = Buyer::query()->whereKey($buyer->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill(['portal_status' => CustomerPortalStatus::Suspended, 'remember_token' => null])->save();

            CustomerInvitation::query()->where('buyer_id', $locked->getKey())
                ->whereNull('used_at')->update(['used_at' => now()]);

            $locked->recordPortalActivity(CustomerActivityType::Suspended, $reason ? "Portal access suspended — {$reason}" : 'Portal access suspended.');
            Log::info('customer.portal_suspended', ['buyer_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }

    public function restore(Buyer $buyer, User $actor): Buyer
    {
        if ($buyer->portal_status !== CustomerPortalStatus::Suspended) {
            throw new DomainException('Portal access is not suspended.');
        }

        return $this->transaction(function () use ($buyer, $actor): Buyer {
            /** @var Buyer $locked */
            $locked = Buyer::query()->whereKey($buyer->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'portal_status' => $locked->password !== null
                    ? CustomerPortalStatus::Active
                    : CustomerPortalStatus::None,
            ])->save();

            $locked->recordPortalActivity(CustomerActivityType::Restored, 'Portal access restored.');
            Log::info('customer.portal_restored', ['buyer_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }
}
