<?php

declare(strict_types=1);

use App\Actions\Masters\DeleteMaster;
use App\Enums\LeadActivityType;
use App\Exceptions\DomainException;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadFollowUp;
use App\Models\Masters\LeadSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the lead tables with the expected columns and soft deletes', function () {
    expect(Schema::hasTable('leads'))->toBeTrue()
        ->and(Schema::hasColumns('leads', [
            'name', 'phone', 'email', 'lead_source_id', 'assigned_to',
            'status', 'notes', 'follow_up_at', 'converted_at', 'converted_by', 'buyer_id', 'deleted_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('lead_follow_ups'))->toBeTrue()
        ->and(Schema::hasTable('lead_activities'))->toBeTrue()
        ->and(Schema::hasTable('lead_sources'))->toBeTrue();
});

it('does NOT make lead phone globally unique', function () {
    Lead::factory()->create(['phone' => '9876543210']);
    Lead::factory()->create(['phone' => '9876543210']);

    expect(Lead::where('phone', '9876543210')->count())->toBe(2);
});

it('cascades follow-ups and activities when a lead is force-deleted', function () {
    $lead = Lead::factory()->create();
    LeadFollowUp::factory()->for($lead)->create();
    $lead->recordActivity(LeadActivityType::Created, 'x');

    $lead->forceDelete();

    expect(LeadFollowUp::where('lead_id', $lead->id)->count())->toBe(0)
        ->and(LeadActivity::where('lead_id', $lead->id)->count())->toBe(0);
});

it('refuses to delete a lead source that still has leads', function () {
    $source = LeadSource::factory()->create();
    Lead::factory()->create(['lead_source_id' => $source->id]);

    expect(fn () => app(DeleteMaster::class)->handle($source))
        ->toThrow(DomainException::class);

    expect(LeadSource::find($source->id))->not->toBeNull();
});

it('activity rows are append-only (no updated_at)', function () {
    expect(Schema::hasColumn('lead_activities', 'updated_at'))->toBeFalse();
});
