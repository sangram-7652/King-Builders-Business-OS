<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Enums\PriceCalculationType;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\ChargeType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ChargeTypeResource extends MasterResource
{
    public function model(): string
    {
        return ChargeType::class;
    }

    public function slug(): string
    {
        return 'charge-types';
    }

    public function singularLabel(): string
    {
        return 'charge type';
    }

    public function pluralLabel(): string
    {
        return 'charge types';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Finance;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required()->help('e.g. Development, Maintenance, Legal, Documentation.'),
            Field::text('code', 'Code')->required(),
            Field::select('calculation_type', 'Calculation type', PriceCalculationType::options())
                ->required()
                ->help('Fixed amount, a percentage of the base price, or a rate per sq ft.'),
            Field::number('value', 'Value', '0.0001')->required()->help('Amount / percentage / rate per the calculation type.'),
            Field::text('description', 'Description'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        $isPercentage = ($state['calculation_type'] ?? null) === PriceCalculationType::Percentage->value;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('charge_types', 'name')->ignore($id)->withoutTrashed()],
            'code' => ['required', 'string', 'max:32', Rule::unique('charge_types', 'code')->ignore($id)->withoutTrashed()],
            'calculation_type' => ['required', new Enum(PriceCalculationType::class)],
            'value' => ['required', 'numeric', 'min:0', $isPercentage ? 'max:100' : 'max:99999999999.9999'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
