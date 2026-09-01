<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Actions\Buyers\Concerns\ValidatesBuyerLocation;
use App\Enums\BuyerStatus;
use App\Models\Buyer;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\Log;

class CreateBuyer
{
    use RunsInTransaction;
    use ValidatesBuyerLocation;

    public function __construct(private readonly SequenceGenerator $sequences) {}

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(array $data, User $actor): Buyer
    {
        return $this->transaction(function () use ($data, $actor): Buyer {
            $this->assertCityBelongsToState($data['state_id'] ?? null, $data['city_id'] ?? null);

            // Concurrency-safe: the sequence row is locked FOR UPDATE here.
            $code = Buyer::formatCode($this->sequences->next(Buyer::SEQUENCE_KEY));

            $buyer = Buyer::create([
                'customer_code' => $code,
                'first_name' => trim($data['first_name']),
                'middle_name' => $data['middle_name'] ?: null,
                'last_name' => $data['last_name'] ?: null,
                'phone' => trim($data['phone']),
                'alternate_phone' => $data['alternate_phone'] ?: null,
                'email' => $data['email'] ? trim($data['email']) : null,
                'date_of_birth' => $data['date_of_birth'] ?: null,
                'gender' => $data['gender'] ?: null,
                'occupation' => $data['occupation'] ?: null,
                'address' => $data['address'] ?: null,
                'state_id' => $data['state_id'] ?: null,
                'city_id' => $data['city_id'] ?: null,
                'pincode' => $data['pincode'] ?: null,
                'pan_number' => $data['pan_number'] ? strtoupper(trim($data['pan_number'])) : null,
                'aadhaar_number' => $data['aadhaar_number'] ? preg_replace('/\s+/', '', $data['aadhaar_number']) : null,
                'status' => BuyerStatus::Active,
                'created_by' => $actor->id,
            ]);

            // Never log PAN / Aadhaar.
            Log::info('buyer.created', ['buyer_id' => $buyer->id, 'customer_code' => $buyer->customer_code, 'by' => $actor->id]);

            return $buyer;
        });
    }
}
