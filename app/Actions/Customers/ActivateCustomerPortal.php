<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\CustomerActivityType;
use App\Enums\CustomerPortalStatus;
use App\Exceptions\DomainException;
use App\Models\Buyer;
use App\Models\CustomerInvitation;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Customers\CustomerTokenService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Consumes an activation or reset token and sets the customer's portal
 * password (M15). The token is single-use — marked spent inside the same
 * transaction — and must not be expired.
 */
class ActivateCustomerPortal
{
    use RunsInTransaction;

    public function __construct(private readonly CustomerTokenService $tokens) {}

    public function handle(string $rawToken, string $purpose, string $password): Buyer
    {
        $invitation = $this->tokens->resolve($rawToken, $purpose);

        if ($invitation === null) {
            throw new DomainException('This link is invalid or has expired. Please ask us for a new one.');
        }

        return $this->transaction(function () use ($invitation, $purpose, $password): Buyer {
            /** @var CustomerInvitation $lockedInvite */
            $lockedInvite = CustomerInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedInvite->isUsable()) {
                throw new DomainException('This link has already been used or has expired.');
            }

            /** @var Buyer $buyer */
            $buyer = Buyer::query()->whereKey($lockedInvite->buyer_id)->lockForUpdate()->firstOrFail();

            $buyer->forceFill([
                'password' => Hash::make($password),
                'portal_status' => CustomerPortalStatus::Active,
                'portal_activated_at' => $buyer->portal_activated_at ?? now(),
                'remember_token' => null,
            ])->save();

            $lockedInvite->forceFill(['used_at' => now()])->save();

            // Spend any other outstanding token for this buyer.
            CustomerInvitation::query()
                ->where('buyer_id', $buyer->getKey())
                ->whereKeyNot($lockedInvite->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $type = $purpose === CustomerInvitation::PURPOSE_RESET
                ? CustomerActivityType::PasswordReset
                : CustomerActivityType::Activated;

            $buyer->recordPortalActivity($type);

            Log::info('customer.portal_'.($purpose === CustomerInvitation::PURPOSE_RESET ? 'password_reset' : 'activated'), [
                'buyer_id' => $buyer->id,
            ]);

            return $buyer;
        });
    }
}
