<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\AreaUnit;
use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\PlotSize;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class PlotSizeResource extends MasterResource
{
    public function model(): string
    {
        return PlotSize::class;
    }

    public function slug(): string
    {
        return 'plot-sizes';
    }

    public function singularLabel(): string
    {
        return 'Plot size';
    }

    public function pluralLabel(): string
    {
        return 'Plot sizes / areas';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Property;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required()->help('e.g. "1776 sq ft plot".'),
            Field::number('area', 'Area', '0.01')->required()->help('Numeric area — do not include the unit here.'),
            Field::select('unit', 'Unit', AreaUnit::options())->required(),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('plot_sizes', 'name')->ignore($id)->withoutTrashed()],
            'area' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'unit' => ['required', new Enum(AreaUnit::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
