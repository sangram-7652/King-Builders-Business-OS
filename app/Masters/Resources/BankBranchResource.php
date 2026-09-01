<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\Bank;
use App\Models\Masters\BankBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class BankBranchResource extends MasterResource
{
    public function model(): string
    {
        return BankBranch::class;
    }

    public function slug(): string
    {
        return 'bank-branches';
    }

    public function singularLabel(): string
    {
        return 'Bank branch';
    }

    public function pluralLabel(): string
    {
        return 'Bank branches';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Finance;
    }

    public function with(): array
    {
        return ['bank'];
    }

    public function scopeList(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function filters(): array
    {
        return [[
            'key' => 'bank_id',
            'label' => 'Bank',
            'options' => Bank::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]];
    }

    public function fields(): array
    {
        return [
            Field::select('bank_id', 'Bank', fn () => Bank::query()->active()->ordered()->pluck('name', 'id')->all())
                ->required()
                ->tableLabel('Bank')
                ->display(fn ($row) => $row->bank?->name),
            Field::text('name', 'Branch name')->required(),
            Field::text('ifsc', 'IFSC')->help('11 characters, e.g. HDFC0001234.')->placeholder('HDFC0001234'),
            Field::text('address', 'Address'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'bank_id' => ['required', 'integer', Rule::exists('banks', 'id')->withoutTrashed()],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('bank_branches', 'name')
                    ->where('bank_id', $state['bank_id'] ?? null)
                    ->ignore($id)
                    ->withoutTrashed(),
            ],
            'ifsc' => [
                'nullable', 'string', 'size:11', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/',
                Rule::unique('bank_branches', 'ifsc')->ignore($id)->withoutTrashed(),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }

    public function toAttributes(array $state): array
    {
        $attributes = parent::toAttributes($state);

        if (isset($attributes['ifsc']) && is_string($attributes['ifsc'])) {
            $attributes['ifsc'] = strtoupper($attributes['ifsc']) ?: null;
        }

        return $attributes;
    }
}
