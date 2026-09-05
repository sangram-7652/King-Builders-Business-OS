<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Enums\DocumentScope;
use App\Models\Masters\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // Buyer KYC
            ['code' => 'AADHAAR', 'name' => 'Aadhaar', 'applies_to' => DocumentScope::Buyer, 'default_required' => true, 'supports_expiry' => false],
            ['code' => 'PAN', 'name' => 'PAN', 'applies_to' => DocumentScope::Buyer, 'default_required' => true, 'supports_expiry' => false],
            ['code' => 'ADDRESS_PROOF', 'name' => 'Address Proof', 'applies_to' => DocumentScope::Buyer, 'default_required' => true, 'supports_expiry' => true],
            ['code' => 'PHOTO', 'name' => 'Photograph', 'applies_to' => DocumentScope::Buyer, 'default_required' => true, 'supports_expiry' => false],
            ['code' => 'BANK_PROOF', 'name' => 'Bank Proof', 'applies_to' => DocumentScope::Buyer, 'default_required' => false, 'supports_expiry' => false],
            // Booking
            ['code' => 'BOOKING_FORM', 'name' => 'Booking Form', 'applies_to' => DocumentScope::Booking, 'default_required' => true, 'supports_expiry' => false],
            ['code' => 'BOOKING_AGREEMENT', 'name' => 'Booking Agreement', 'applies_to' => DocumentScope::Booking, 'default_required' => true, 'supports_expiry' => false],
            ['code' => 'PAYMENT_PROOF', 'name' => 'Payment Document', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            ['code' => 'REGISTRY_DOC', 'name' => 'Registry Document', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            ['code' => 'REGISTERED_DEED', 'name' => 'Registered Deed', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            ['code' => 'HANDOVER_ACK', 'name' => 'Handover Acknowledgement', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            ['code' => 'POSSESSION_LETTER', 'name' => 'Possession Letter', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            ['code' => 'NOC', 'name' => 'NOC', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => true],
            // Possession + transfer (M10)
            ['code' => 'POSSESSION_CERTIFICATE', 'name' => 'Possession Certificate', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            ['code' => 'POSSESSION_ACK', 'name' => 'Possession Acknowledgement', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            ['code' => 'SITE_INSPECTION_REPORT', 'name' => 'Site Inspection Report', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            ['code' => 'TRANSFER_APPLICATION', 'name' => 'Transfer Application', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            ['code' => 'TRANSFER_CONSENT', 'name' => 'Transfer Consent', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            ['code' => 'TRANSFER_ID_PROOF', 'name' => 'Transfer ID Proof', 'applies_to' => DocumentScope::Booking, 'default_required' => false, 'supports_expiry' => false],
            // Channel partner KYC (M14)
            ['code' => 'PARTNER_PAN', 'name' => 'Partner PAN Card', 'applies_to' => DocumentScope::Partner, 'default_required' => true, 'supports_expiry' => false],
            ['code' => 'PARTNER_ADDRESS_PROOF', 'name' => 'Partner Address Proof', 'applies_to' => DocumentScope::Partner, 'default_required' => true, 'supports_expiry' => true],
            ['code' => 'PARTNER_BANK_PROOF', 'name' => 'Partner Bank Proof / Cancelled Cheque', 'applies_to' => DocumentScope::Partner, 'default_required' => true, 'supports_expiry' => false],
            ['code' => 'PARTNER_AGREEMENT', 'name' => 'Channel Partner Agreement', 'applies_to' => DocumentScope::Partner, 'default_required' => true, 'supports_expiry' => true],
            ['code' => 'PARTNER_RERA_CERTIFICATE', 'name' => 'Partner RERA Certificate', 'applies_to' => DocumentScope::Partner, 'default_required' => false, 'supports_expiry' => true],
            ['code' => 'PARTNER_GST_CERTIFICATE', 'name' => 'Partner Registration Certificate', 'applies_to' => DocumentScope::Partner, 'default_required' => false, 'supports_expiry' => false],
        ];

        foreach ($rows as $i => $row) {
            DocumentType::updateOrCreate(
                ['name' => $row['name']],
                [
                    'code' => $row['code'],
                    'applies_to' => $row['applies_to'],
                    'default_required' => $row['default_required'],
                    'supports_expiry' => $row['supports_expiry'],
                    'is_system' => true,
                    'sort_order' => $i,
                    'is_active' => true,
                ],
            );
        }
    }
}
