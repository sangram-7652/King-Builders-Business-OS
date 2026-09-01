<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Enums\Masters\PlcCalculationType;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\PlcType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class PlcTypeResource extends MasterResource
{
    public function model(): string
    {
        return PlcType::class;
    }

    public function slug(): string
    {
        return 'plc-types';
    }

    public function singularLabel(): string
    {
        return 'PLC type';
    }

    public function pluralLabel(): string
    {
        return 'PLC types';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Property;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required()->help('e.g. Corner, Park facing, Main road.'),
            Field::text('code', 'Code')->required(),
            Field::select('calculation_type', 'Calculation type', PlcCalculationType::options())
                ->required()
                ->help('Fixed amount, per sq ft, or a percentage. The pricing engine (M6) consumes this.'),
            Field::number('value', 'Value', '0.01')->required()->help('Amount / rate / percentage per the calculation type.'),
            Field::text('description', 'Description'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        $isPercentage = ($state['calculation_type'] ?? null) === PlcCalculationType::Percentage->value;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('plc_types', 'code')->ignore($id)->withoutTrashed()],
            'calculation_type' => ['required', new Enum(PlcCalculationType::class)],
            'value' => ['required', 'numeric', 'min:0', $isPercentage ? 'max:100' : 'max:999999999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
