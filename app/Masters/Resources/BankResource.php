<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\Bank;
use Illuminate\Validation\Rule;

class BankResource extends MasterResource
{
    public function model(): string
    {
        return Bank::class;
    }

    public function slug(): string
    {
        return 'banks';
    }

    public function singularLabel(): string
    {
        return 'Bank';
    }

    public function pluralLabel(): string
    {
        return 'Banks';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Finance;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required(),
            Field::text('code', 'Code')->help('Optional short code, e.g. HDFC, SBI.'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('banks', 'name')->ignore($id)->withoutTrashed()],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('banks', 'code')->ignore($id)->withoutTrashed()],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
