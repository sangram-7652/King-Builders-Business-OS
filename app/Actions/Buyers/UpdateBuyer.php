<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Actions\Buyers\Concerns\ValidatesBuyerLocation;
use App\Models\Buyer;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

class UpdateBuyer
{
    use RunsInTransaction;
    use ValidatesBuyerLocation;

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(Buyer $buyer, array $data): Buyer
    {
        return $this->transaction(function () use ($buyer, $data): Buyer {
            $this->assertCityBelongsToState($data['state_id'] ?? null, $data['city_id'] ?? null);

            $buyer->fill([
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
            ]);

            // Sensitive fields are only overwritten when a new value is supplied.
            if (array_key_exists('pan_number', $data) && $data['pan_number'] !== null && $data['pan_number'] !== '') {
                $buyer->pan_number = strtoupper(trim($data['pan_number']));
            }
            if (array_key_exists('aadhaar_number', $data) && $data['aadhaar_number'] !== null && $data['aadhaar_number'] !== '') {
                $buyer->aadhaar_number = preg_replace('/\s+/', '', $data['aadhaar_number']);
            }

            $buyer->save();

            Log::info('buyer.updated', ['buyer_id' => $buyer->id, 'by' => auth()->id()]);

            return $buyer->refresh();
        });
    }
}
