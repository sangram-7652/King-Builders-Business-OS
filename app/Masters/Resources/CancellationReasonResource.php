<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\CancellationReason;
use Illuminate\Validation\Rule;

class CancellationReasonResource extends MasterResource
{
    public function model(): string
    {
        return CancellationReason::class;
    }

    public function slug(): string
    {
        return 'cancellation-reasons';
    }

    public function singularLabel(): string
    {
        return 'Cancellation reason';
    }

    public function pluralLabel(): string
    {
        return 'Cancellation reasons';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Operations;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required(),
            Field::text('description', 'Description'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('cancellation_reasons', 'name')->ignore($id)->withoutTrashed()],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
