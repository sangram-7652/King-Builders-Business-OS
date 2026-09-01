<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\PaymentType;
use Illuminate\Validation\Rule;

class PaymentTypeResource extends MasterResource
{
    public function model(): string
    {
        return PaymentType::class;
    }

    public function slug(): string
    {
        return 'payment-types';
    }

    public function singularLabel(): string
    {
        return 'Payment type';
    }

    public function pluralLabel(): string
    {
        return 'Payment types';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Finance;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required()->help('e.g. Booking Amount, Installment, Refund.'),
            Field::text('code', 'Code')->required(),
            Field::text('description', 'Description'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('payment_types', 'name')->ignore($id)->withoutTrashed()],
            'code' => ['required', 'string', 'max:32', Rule::unique('payment_types', 'code')->ignore($id)->withoutTrashed()],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
