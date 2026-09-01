<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\TaxRate;
use Illuminate\Validation\Rule;

class TaxRateResource extends MasterResource
{
    public function model(): string
    {
        return TaxRate::class;
    }

    public function slug(): string
    {
        return 'tax-rates';
    }

    public function singularLabel(): string
    {
        return 'tax rate';
    }

    public function pluralLabel(): string
    {
        return 'tax rates';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Finance;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required()->help('e.g. GST 5%, Stamp duty.'),
            Field::text('code', 'Code')->required(),
            Field::number('percentage', 'Percentage', '0.0001')->required()->suffix('%')
                ->help('Applied to the taxable amount (subtotal − discount).'),
            Field::text('description', 'Description'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('tax_rates', 'name')->ignore($id)->withoutTrashed()],
            'code' => ['required', 'string', 'max:32', Rule::unique('tax_rates', 'code')->ignore($id)->withoutTrashed()],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
