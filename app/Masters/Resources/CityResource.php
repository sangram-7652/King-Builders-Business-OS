<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\City;
use App\Models\Masters\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class CityResource extends MasterResource
{
    public function model(): string
    {
        return City::class;
    }

    public function slug(): string
    {
        return 'cities';
    }

    public function singularLabel(): string
    {
        return 'City';
    }

    public function pluralLabel(): string
    {
        return 'Cities';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Location;
    }

    public function with(): array
    {
        return ['state'];
    }

    public function scopeList(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function filters(): array
    {
        return [[
            'key' => 'state_id',
            'label' => 'State',
            'options' => State::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]];
    }

    public function fields(): array
    {
        return [
            Field::select('state_id', 'State', fn () => State::query()->active()->ordered()->pluck('name', 'id')->all())
                ->required()
                ->tableLabel('State')
                ->display(fn ($row) => $row->state?->name),
            Field::text('name', 'Name')->required(),
            Field::text('code', 'Code'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'state_id' => ['required', 'integer', Rule::exists('states', 'id')->withoutTrashed()],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('cities', 'name')
                    ->where('state_id', $state['state_id'] ?? null)
                    ->ignore($id)
                    ->withoutTrashed(),
            ],
            'code' => ['nullable', 'string', 'max:12'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
