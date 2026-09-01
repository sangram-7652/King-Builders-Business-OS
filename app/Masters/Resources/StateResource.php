<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\State;
use Illuminate\Validation\Rule;

class StateResource extends MasterResource
{
    public function model(): string
    {
        return State::class;
    }

    public function slug(): string
    {
        return 'states';
    }

    public function singularLabel(): string
    {
        return 'State';
    }

    public function pluralLabel(): string
    {
        return 'States';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Location;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required(),
            Field::text('code', 'Code')->required()->help('e.g. an ISO / GST state code.'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('states', 'name')->ignore($id)->withoutTrashed()],
            'code' => ['required', 'string', 'max:12', Rule::unique('states', 'code')->ignore($id)->withoutTrashed()],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }

    public function toAttributes(array $state): array
    {
        $attributes = parent::toAttributes($state);
        if (isset($attributes['code']) && is_string($attributes['code'])) {
            $attributes['code'] = strtoupper($attributes['code']);
        }

        return $attributes;
    }
}
