<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Actions\Partners\Concerns\ValidatesPartnerLocation;
use App\Enums\PartnerActivityType;
use App\Enums\PartnerType;
use App\Models\Partner;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Updates a partner's profile / KYC / payout fields (M14). Status is NOT
 * touched here — that goes through {@see ChangePartnerStatusAction}.
 */
class UpdatePartnerAction
{
    use RunsInTransaction;
    use ValidatesPartnerLocation;

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(Partner $partner, array $data, User $actor): Partner
    {
        return $this->transaction(function () use ($partner, $data, $actor): Partner {
            $this->assertCityBelongsToState($data['state_id'] ?? null, $data['city_id'] ?? null);

            /** @var Partner $locked */
            $locked = Partner::query()->whereKey($partner->getKey())->lockForUpdate()->firstOrFail();

            $bankBefore = [
                $locked->bank_account_name, $locked->bank_account_number,
                $locked->bank_ifsc, $locked->bank_name,
            ];

            $locked->fill([
                'type' => PartnerType::from($data['type']),
                'name' => trim($data['name']),
                'company_name' => $data['company_name'] ?: null,
                'contact_person' => $data['contact_person'] ?: null,
                'phone' => trim($data['phone']),
                'alternate_phone' => $data['alternate_phone'] ?: null,
                'email' => $data['email'] ? trim($data['email']) : null,
                'address' => $data['address'] ?: null,
                'state_id' => $data['state_id'] ?: null,
                'city_id' => $data['city_id'] ?: null,
                'pincode' => $data['pincode'] ?: null,
                'pan_number' => $data['pan_number'] ? strtoupper(trim($data['pan_number'])) : null,
                'rera_number' => $data['rera_number'] ?: null,
                'bank_account_name' => $data['bank_account_name'] ?: null,
                'bank_account_number' => $data['bank_account_number'] ? preg_replace('/\s+/', '', $data['bank_account_number']) : null,
                'bank_ifsc' => $data['bank_ifsc'] ? strtoupper(trim($data['bank_ifsc'])) : null,
                'bank_name' => $data['bank_name'] ?: null,
                'notes' => $data['notes'] ?: null,
            ])->save();

            $bankAfter = [
                $locked->bank_account_name, $locked->bank_account_number,
                $locked->bank_ifsc, $locked->bank_name,
            ];

            $locked->recordActivity(PartnerActivityType::Updated, 'Partner details updated', [], $actor);

            if ($bankBefore !== $bankAfter) {
                $locked->recordActivity(PartnerActivityType::BankDetailsUpdated, 'Payout bank details updated', [], $actor);
            }

            Log::info('partner.updated', ['partner_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }
}
