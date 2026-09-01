<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\InterestFrequency;
use App\Enums\Masters\InterestType;
use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\InterestRule;
use Illuminate\Validation\Rules\Enum;

class InterestRuleResource extends MasterResource
{
    public function model(): string
    {
        return InterestRule::class;
    }

    public function slug(): string
    {
        return 'interest-rules';
    }

    public function singularLabel(): string
    {
        return 'Interest rule';
    }

    public function pluralLabel(): string
    {
        return 'Interest rules';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Finance;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required(),
            Field::select('interest_type', 'Interest type', InterestType::options())->required(),
            Field::number('rate', 'Rate', '0.0001')->required()->suffix('% p.a.')->help('Annualised rate, 0–100.'),
            Field::select('frequency', 'Compounding / charge frequency', InterestFrequency::options())->required(),
            Field::number('grace_period_days', 'Grace period (days)')->required(),
            Field::date('effective_from', 'Effective from')->required(),
            Field::date('effective_until', 'Effective until')->help('Leave blank for open-ended.'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'interest_type' => ['required', new Enum(InterestType::class)],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'frequency' => ['required', new Enum(InterestFrequency::class)],
            'grace_period_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
