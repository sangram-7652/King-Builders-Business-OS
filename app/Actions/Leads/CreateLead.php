<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

class CreateLead
{
    use RunsInTransaction;

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(array $data, User $actor): Lead
    {
        return $this->transaction(function () use ($data, $actor): Lead {
            $lead = Lead::create([
                'name' => trim($data['name']),
                'phone' => trim($data['phone']),
                'email' => $data['email'] ? trim($data['email']) : null,
                'lead_source_id' => $data['lead_source_id'] ?: null,
                'assigned_to' => $data['assigned_to'] ?: null,
                'status' => LeadStatus::New,
                'notes' => $data['notes'] ?: null,
                'created_by' => $actor->id,
            ]);

            $lead->recordActivity(LeadActivityType::Created, 'Lead created', [
                'source_id' => $lead->lead_source_id,
            ], $actor);

            if ($lead->assigned_to !== null) {
                $lead->recordActivity(LeadActivityType::Assigned, 'Assigned on creation', [
                    'assigned_to' => $lead->assigned_to,
                ], $actor);
            }

            Log::info('lead.created', ['lead_id' => $lead->id, 'by' => $actor->id]);

            return $lead;
        });
    }
}
