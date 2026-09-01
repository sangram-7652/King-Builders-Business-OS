<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\PaymentMode;
use Illuminate\Validation\Rule;

class PaymentModeResource extends MasterResource
{
    public function model(): string
    {
        return PaymentMode::class;
    }

    public function slug(): string
    {
        return 'payment-modes';
    }

    public function singularLabel(): string
    {
        return 'Payment mode';
    }

    public function pluralLabel(): string
    {
        return 'Payment modes';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Finance;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required()->help('e.g. Cash, Cheque, RTGS, NEFT, UPI.'),
            Field::text('code', 'Code')->required(),
            Field::text('description', 'Description'),
            Field::toggle('requires_reference', 'Requires a reference number')
                ->help('e.g. cheque number, UTR, transaction id.'),
            Field::toggle('is_cheque', 'Is a cheque mode')
                ->help('Payments with this mode collect cheque details and run the cheque lifecycle.'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('payment_modes', 'name')->ignore($id)->withoutTrashed()],
            'code' => ['required', 'string', 'max:32', Rule::unique('payment_modes', 'code')->ignore($id)->withoutTrashed()],
            'description' => ['nullable', 'string', 'max:255'],
            'requires_reference' => ['boolean'],
            'is_cheque' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
