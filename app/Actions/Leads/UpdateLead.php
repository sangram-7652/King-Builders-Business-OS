<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Edits a lead's descriptive fields. Status, assignment and conversion are
 * handled by their own Actions.
 */
class UpdateLead
{
    use RunsInTransaction;

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(Lead $lead, array $data): Lead
    {
        return $this->transaction(function () use ($lead, $data): Lead {
            $lead->fill([
                'name' => trim($data['name']),
                'phone' => trim($data['phone']),
                'email' => $data['email'] ? trim($data['email']) : null,
                'lead_source_id' => $data['lead_source_id'] ?: null,
                'notes' => $data['notes'] ?: null,
            ])->save();

            Log::info('lead.updated', ['lead_id' => $lead->id, 'by' => auth()->id()]);

            return $lead->refresh();
        });
    }
}
