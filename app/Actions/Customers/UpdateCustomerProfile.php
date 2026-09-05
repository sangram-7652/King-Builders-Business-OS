<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\CustomerActivityType;
use App\Models\Buyer;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * A customer's own edit of their contact details (M15). Deliberately narrow —
 * a customer can never change their customer code, status, KYC identifiers,
 * portal status or any staff-managed field. Mass-assignment safe: only the
 * whitelisted keys below are written.
 */
class UpdateCustomerProfile
{
    use RunsInTransaction;

    /** @var list<string> */
    private const EDITABLE = [
        'phone', 'alternate_phone', 'address', 'state_id', 'city_id', 'pincode', 'occupation',
    ];

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(Buyer $customer, array $data): Buyer
    {
        return $this->transaction(function () use ($customer, $data): Buyer {
            /** @var Buyer $locked */
            $locked = Buyer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $payload = [];
            foreach (self::EDITABLE as $key) {
                if (array_key_exists($key, $data)) {
                    $payload[$key] = $data[$key] === '' ? null : $data[$key];
                }
            }

            $locked->forceFill($payload)->save();
            $locked->recordPortalActivity(CustomerActivityType::ProfileUpdated, 'Contact details updated.', [
                'fields' => implode(',', array_keys($payload)),
            ]);

            Log::info('customer.profile_updated', ['buyer_id' => $locked->id]);

            return $locked;
        });
    }
}
