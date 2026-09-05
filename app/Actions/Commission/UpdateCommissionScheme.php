<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\CommissionBasis;
use App\Enums\PartnerType;
use App\Exceptions\DomainException;
use App\Models\CommissionScheme;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Edits a DRAFT commission scheme's metadata (M14.3). A published or archived
 * version is frozen and cannot be edited — create a new version instead.
 */
class UpdateCommissionScheme
{
    use RunsInTransaction;

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(CommissionScheme $scheme, array $data, User $actor): CommissionScheme
    {
        return $this->transaction(function () use ($scheme, $data, $actor): CommissionScheme {
            /** @var CommissionScheme $locked */
            $locked = CommissionScheme::query()->whereKey($scheme->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->isImmutable()) {
                throw new DomainException("A {$locked->status->label()} scheme version cannot be edited. Create a new version instead.");
            }

            $locked->fill([
                'name' => trim($data['name']),
                'description' => $data['description'] ?: null,
                'basis' => CommissionBasis::from($data['basis']),
                'partner_type' => ($data['partner_type'] ?? null) ? PartnerType::from($data['partner_type']) : null,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'effective_from' => $data['effective_from'] ?: null,
                'effective_to' => $data['effective_to'] ?: null,
            ])->save();

            Log::info('commission_scheme.updated', ['scheme_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }
}
