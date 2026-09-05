<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\CommissionSchemeStatus;
use App\Exceptions\DomainException;
use App\Models\CommissionRule;
use App\Models\CommissionScheme;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Starts a new DRAFT version of a commission scheme family (M14.3) by cloning
 * the latest version's metadata, rules and slabs. The existing published
 * version stays in force until the new draft is itself published.
 *
 * At most one DRAFT version per family at a time.
 */
class CreateCommissionSchemeVersion
{
    use RunsInTransaction;

    public function handle(CommissionScheme $scheme, User $actor): CommissionScheme
    {
        return $this->transaction(function () use ($scheme, $actor): CommissionScheme {
            $latest = CommissionScheme::query()
                ->where('code', $scheme->code)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->firstOrFail();

            $existingDraft = CommissionScheme::query()
                ->where('code', $scheme->code)
                ->where('status', CommissionSchemeStatus::Draft->value)
                ->first();

            if ($existingDraft !== null) {
                throw new DomainException("Scheme {$scheme->code} already has an open draft (v{$existingDraft->version}).");
            }

            $latest->loadMissing('rules.slabs');

            $copy = CommissionScheme::create([
                'code' => $latest->code,
                'version' => $latest->version + 1,
                'name' => $latest->name,
                'description' => $latest->description,
                'status' => CommissionSchemeStatus::Draft,
                'basis' => $latest->basis,
                'partner_type' => $latest->partner_type,
                'is_default' => $latest->is_default,
                'effective_from' => null,
                'effective_to' => null,
                'created_by' => $actor->id,
            ]);

            foreach ($latest->rules as $rule) {
                /** @var CommissionRule $newRule */
                $newRule = $copy->rules()->create([
                    'project_id' => $rule->project_id,
                    'calc_type' => $rule->calc_type,
                    'rate' => $rule->rate,
                    'flat_amount' => $rule->flat_amount,
                    'slab_mode' => $rule->slab_mode,
                    'min_amount' => $rule->min_amount,
                    'max_amount' => $rule->max_amount,
                    'notes' => $rule->notes,
                ]);

                foreach ($rule->slabs as $slab) {
                    $newRule->slabs()->create([
                        'sort_order' => $slab->sort_order,
                        'from_amount' => $slab->from_amount,
                        'to_amount' => $slab->to_amount,
                        'calc_type' => $slab->calc_type,
                        'rate' => $slab->rate,
                        'flat_amount' => $slab->flat_amount,
                    ]);
                }
            }

            Log::info('commission_scheme.version_created', [
                'scheme_id' => $copy->id, 'code' => $copy->code, 'version' => $copy->version, 'by' => $actor->id,
            ]);

            return $copy->load('rules.slabs');
        });
    }
}
