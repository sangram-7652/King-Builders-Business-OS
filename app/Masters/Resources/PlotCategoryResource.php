<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\PlotCategory;
use Illuminate\Validation\Rule;

class PlotCategoryResource extends MasterResource
{
    public function model(): string
    {
        return PlotCategory::class;
    }

    public function slug(): string
    {
        return 'plot-categories';
    }

    public function singularLabel(): string
    {
        return 'Plot category';
    }

    public function pluralLabel(): string
    {
        return 'Plot categories';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Property;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required(),
            Field::text('code', 'Code')->required()->help('Unique short code, e.g. RESI, COMM.'),
            Field::text('description', 'Description'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('plot_categories', 'code')->ignore($id)->withoutTrashed()],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
