<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\CommissionBasis;
use App\Enums\CommissionSchemeStatus;
use App\Enums\PartnerType;
use App\Models\CommissionScheme;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Creates a new commission scheme family (M14.3) at version 1, DRAFT.
 * `code` (CMS-000001) is concurrency-safe. The scheme has no calculation until
 * a rule is added via {@see SaveCommissionRule} and it is published.
 */
class CreateCommissionScheme
{
    use RunsInTransaction;

    public function __construct(private readonly SequenceGenerator $sequences) {}

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(array $data, User $actor): CommissionScheme
    {
        return $this->transaction(function () use ($data, $actor): CommissionScheme {
            $code = CommissionScheme::formatCode($this->sequences->next(CommissionScheme::SEQUENCE_KEY));

            $scheme = CommissionScheme::create([
                'code' => $code,
                'version' => 1,
                'name' => trim($data['name']),
                'description' => $data['description'] ?: null,
                'status' => CommissionSchemeStatus::Draft,
                'basis' => CommissionBasis::from($data['basis']),
                'partner_type' => ($data['partner_type'] ?? null) ? PartnerType::from($data['partner_type']) : null,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'effective_from' => $data['effective_from'] ?: null,
                'effective_to' => $data['effective_to'] ?: null,
                'created_by' => $actor->id,
            ]);

            Log::info('commission_scheme.created', ['scheme_id' => $scheme->id, 'code' => $code, 'by' => $actor->id]);

            return $scheme;
        });
    }
}
