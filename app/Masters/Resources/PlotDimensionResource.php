<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\LengthUnit;
use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\PlotDimension;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class PlotDimensionResource extends MasterResource
{
    public function model(): string
    {
        return PlotDimension::class;
    }

    public function slug(): string
    {
        return 'plot-dimensions';
    }

    public function singularLabel(): string
    {
        return 'Plot dimension';
    }

    public function pluralLabel(): string
    {
        return 'Plot dimensions';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Property;
    }

    public function fields(): array
    {
        return [
            Field::text('display_name', 'Display name')->required()->help('e.g. "30 × 40 ft".'),
            Field::number('width', 'Width', '0.01')->required(),
            Field::number('length', 'Length', '0.01')->required(),
            Field::select('unit', 'Unit', LengthUnit::options())->required(),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'width' => [
                'required', 'numeric', 'min:0.01', 'max:99999999.99',
                Rule::unique('plot_dimensions', 'width')
                    ->where('length', $state['length'] ?? null)
                    ->where('unit', $state['unit'] ?? null)
                    ->ignore($id)
                    ->withoutTrashed(),
            ],
            'length' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'unit' => ['required', new Enum(LengthUnit::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
