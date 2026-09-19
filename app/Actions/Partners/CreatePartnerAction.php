<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Actions\Partners\Concerns\ValidatesPartnerLocation;
use App\Enums\PartnerActivityType;
use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Exceptions\DomainException;
use App\Models\Partner;
use App\Models\User;
use App\Services\Commission\PromoterLedgerService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Creates a promoter (M14). `partner_code` (PTNR-000001) is concurrency-safe.
 * A new promoter always starts DRAFT — it must be moved to ACTIVE through
 * {@see ChangePartnerStatusAction} before it can be attributed to a booking.
 *
 * An optional `initial_advance` is recorded as a real ledger transaction (F-M14-ADV-1)
 * — never merely stored as an editable balance.
 */
class CreatePartnerAction
{
    use RunsInTransaction;
    use ValidatesPartnerLocation;

    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly PromoterLedgerService $ledger,
    ) {}

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
                'commission_percentage' => ($data['commission_percentage'] ?? null) !== '' && ($data['commission_percentage'] ?? null) !== null
                    ? $data['commission_percentage']
                    : null,
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

            $initialAdvance = trim((string) ($data['initial_advance'] ?? ''));
            if ($initialAdvance !== '' && $initialAdvance !== '0') {
                $amount = Money::of($initialAdvance);
                if (! $amount->isPositive()) {
                    throw new DomainException('The initial advance amount must be greater than zero.');
                }
                $this->ledger->giveAdvance($partner, $amount, 'Initial advance at promoter creation.', $actor);
            }

            // Never log PAN / bank account number.
            Log::info('partner.created', [
                'partner_id' => $partner->id, 'partner_code' => $partner->partner_code, 'by' => $actor->id,
            ]);

            return $partner;
        });
    }
}
