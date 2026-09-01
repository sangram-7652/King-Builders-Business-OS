<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\LeadSource;
use Illuminate\Validation\Rule;

class LeadSourceResource extends MasterResource
{
    public function model(): string
    {
        return LeadSource::class;
    }

    public function slug(): string
    {
        return 'lead-sources';
    }

    public function singularLabel(): string
    {
        return 'Lead source';
    }

    public function pluralLabel(): string
    {
        return 'Lead sources';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Sales;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required()->help('e.g. Website, Google Ads, Referral.'),
            Field::text('code', 'Code'),
            Field::text('description', 'Description'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('lead_sources', 'name')->ignore($id)->withoutTrashed()],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('lead_sources', 'code')->ignore($id)->withoutTrashed()],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
