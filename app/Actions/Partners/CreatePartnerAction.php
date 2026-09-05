<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Actions\Partners\Concerns\ValidatesPartnerLocation;
use App\Enums\PartnerActivityType;
use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Models\Partner;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Creates a channel partner (M14). `partner_code` (PTNR-000001) is
 * concurrency-safe. A new partner always starts DRAFT — it must be moved to
 * ACTIVE through {@see ChangePartnerStatusAction} before it can be attributed.
 */
class CreatePartnerAction
{
    use RunsInTransaction;
    use ValidatesPartnerLocation;

    public function __construct(private readonly SequenceGenerator $sequences) {}

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(array $data, User $actor): Partner
    {
        return $this->transaction(function () use ($data, $actor): Partner {
            $this->assertCityBelongsToState($data['state_id'] ?? null, $data['city_id'] ?? null);

            $code = Partner::formatCode($this->sequences->next(Partner::SEQUENCE_KEY));

            $partner = Partner::create([
                'partner_code' => $code,
                'type' => PartnerType::from($data['type']),
                'status' => PartnerStatus::Draft,
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
                'onboarded_at' => now(),
                'created_by' => $actor->id,
            ]);

            $partner->recordActivity(PartnerActivityType::Created, 'Partner created', [
                'type' => $partner->type->value,
            ], $actor);

            // Never log PAN / bank account number.
            Log::info('partner.created', [
                'partner_id' => $partner->id, 'partner_code' => $partner->partner_code, 'by' => $actor->id,
            ]);

            return $partner;
        });
    }
}
