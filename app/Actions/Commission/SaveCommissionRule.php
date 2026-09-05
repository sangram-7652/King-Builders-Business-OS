<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\CommissionCalcType;
use App\Enums\SlabMode;
use App\Exceptions\DomainException;
use App\Models\CommissionRule;
use App\Models\CommissionScheme;
use App\Models\User;
use App\Support\Commission\SlabSet;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Creates or replaces one rule (default, or a project override) inside a DRAFT
 * commission scheme (M14.3). Percentage / fixed / slab. Slab brackets are fully
 * revalidated and rewritten. A published scheme is frozen and rejected.
 */
class SaveCommissionRule
{
    use RunsInTransaction;

    /**
     * @param  array<string, mixed>  $data  { project_id?, calc_type, rate?, flat_amount?, slab_mode?, min_amount?, max_amount?, notes?, slabs?: list<array> }
     */
    public function handle(CommissionScheme $scheme, array $data, User $actor): CommissionRule
    {
        if ($scheme->status->isImmutable()) {
            throw new DomainException("Rules on a {$scheme->status->label()} scheme cannot be changed.");
        }

        $calcType = CommissionCalcType::from($data['calc_type']);
        $projectId = ($data['project_id'] ?? null) ?: null;

        [$rate, $flat, $slabMode, $slabs] = $this->normalise($calcType, $data);

        return $this->transaction(function () use ($scheme, $data, $calcType, $projectId, $rate, $flat, $slabMode, $slabs, $actor): CommissionRule {
            /** @var CommissionRule $rule */
            $rule = CommissionRule::query()->updateOrCreate(
                ['commission_scheme_id' => $scheme->getKey(), 'project_id' => $projectId],
                [
                    'calc_type' => $calcType,
                    'rate' => $rate,
                    'flat_amount' => $flat,
                    'slab_mode' => $slabMode,
                    'min_amount' => ($data['min_amount'] ?? null) !== null && $data['min_amount'] !== '' ? $data['min_amount'] : null,
                    'max_amount' => ($data['max_amount'] ?? null) !== null && $data['max_amount'] !== '' ? $data['max_amount'] : null,
                    'notes' => $data['notes'] ?? null,
                ],
            );

            $rule->slabs()->delete();

            if ($slabs !== null) {
                foreach ($slabs->slabs as $slab) {
                    $rule->slabs()->create([
                        'sort_order' => $slab['sort_order'],
                        'from_amount' => $slab['from_amount'],
                        'to_amount' => $slab['to_amount'],
                        'calc_type' => $slab['calc_type'],
                        'rate' => $slab['rate'],
                        'flat_amount' => $slab['flat_amount'],
                    ]);
                }
            }

            Log::info('commission_rule.saved', [
                'scheme_id' => $scheme->id, 'rule_id' => $rule->id, 'project_id' => $projectId,
                'calc_type' => $calcType->value, 'by' => $actor->id,
            ]);

            return $rule->load('slabs');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string|null, 1: string|null, 2: SlabMode|null, 3: SlabSet|null}
     */
    private function normalise(CommissionCalcType $calcType, array $data): array
    {
        return match ($calcType) {
            CommissionCalcType::Percentage => [
                $this->requirePositive($data['rate'] ?? null, 'a percentage rate', 100),
                null, null, null,
            ],
            CommissionCalcType::Fixed => [
                null,
                $this->requirePositive($data['flat_amount'] ?? null, 'a fixed amount'),
                null, null,
            ],
            CommissionCalcType::Slab => [
                null, null,
                SlabMode::from($data['slab_mode'] ?? SlabMode::Whole->value),
                SlabSet::fromRows($data['slabs'] ?? []),
            ],
        };
    }

    private function requirePositive(mixed $value, string $what, ?float $max = null): string
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value <= 0) {
            throw new DomainException("This rule needs {$what} greater than zero.");
        }
        if ($max !== null && (float) $value > $max) {
            throw new DomainException("This rule's rate cannot exceed {$max}%.");
        }

        return (string) $value;
    }
}
