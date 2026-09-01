<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\TdsRule;

class TdsRuleResource extends MasterResource
{
    public function model(): string
    {
        return TdsRule::class;
    }

    public function slug(): string
    {
        return 'tds-rules';
    }

    public function singularLabel(): string
    {
        return 'TDS rule';
    }

    public function pluralLabel(): string
    {
        return 'TDS rules';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Finance;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required(),
            Field::number('percentage', 'Percentage', '0.01')->required()->suffix('%')->help('Between 0 and 100.'),
            Field::date('applicable_from', 'Applicable from')->required(),
            Field::date('applicable_until', 'Applicable until')->help('Leave blank for open-ended.'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'applicable_from' => ['required', 'date'],
            'applicable_until' => ['nullable', 'date', 'after_or_equal:applicable_from'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
