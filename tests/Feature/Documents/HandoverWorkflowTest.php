<?php

declare(strict_types=1);

use App\Actions\Handover\HandoverWorkflowAction;
use App\Actions\Registry\RegistryCaseWorkflowAction;
use App\Enums\HandoverStatus;
use App\Enums\RegistryCaseStatus;
use App\Exceptions\DomainException;
use App\Models\DocumentHandover;
use App\Models\RegistryCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Storage::fake('documents');
});

function readyHandover(): array
{
    $s = registryReadyScenario();
    $case = RegistryCase::factory()->forBooking($s['booking'])->status(RegistryCaseStatus::Scheduled)->create();
    $case = app(RegistryCaseWorkflowAction::class)->complete($case, [
        'registered_document_number' => 'RD-100', 'registration_date' => now()->toDateString(),
    ], registryOfficer());

    return [$s, $case->handover->fresh()];
}

/*
| HANDOVER (37-40)
*/

it('creates the handover at registry completion in REGISTRY_COMPLETED (37)', function () {
    [, $handover] = readyHandover();

    expect($handover->status)->toBe(HandoverStatus::RegistryCompleted)
        ->and(DocumentHandover::count())->toBe(1);
});

it('walks documents ready → scheduled → handed over (38)', function () {
    [, $handover] = readyHandover();
    $officer = registryOfficer();

    $handover = app(HandoverWorkflowAction::class)->markDocumentsReady($handover, $officer);
    expect($handover->status)->toBe(HandoverStatus::DocumentsReady);

    $handover = app(HandoverWorkflowAction::class)->schedule($handover->fresh(), [
        'scheduled_at' => now()->addDays(3)->toDateTimeString(),
    ], $officer);
    expect($handover->status)->toBe(HandoverStatus::HandoverScheduled);

    $handover = app(HandoverWorkflowAction::class)->complete($handover->fresh(), [
        'handover_date' => now()->toDateString(),
        'received_by' => 'Ramesh Kumar',
    ], $officer, fakeDocument('ack.pdf'));

    expect($handover->status)->toBe(HandoverStatus::HandedOver)
        ->and($handover->received_by)->toBe('Ramesh Kumar')
        ->and($handover->completed_at)->not->toBeNull()
        ->and($handover->document)->not->toBeNull();
});

it('requires received_by and handover.complete to complete (39)', function () {
    [, $handover] = readyHandover();
    $handover = app(HandoverWorkflowAction::class)->markDocumentsReady($handover, registryOfficer());

    expect(fn () => app(HandoverWorkflowAction::class)->complete($handover->fresh(), [
        'handover_date' => now()->toDateString(), 'received_by' => '  ',
    ], registryOfficer()))->toThrow(DomainException::class);

    $noComplete = makeUser(permissions: ['handover.view', 'handover.create', 'bookings.view']);
    expect(fn () => app(HandoverWorkflowAction::class)->complete($handover->fresh(), [
        'handover_date' => now()->toDateString(), 'received_by' => 'X',
    ], $noComplete))->toThrow(DomainException::class);
});

it('never records a duplicate completed handover — completion is idempotent (40)', function () {
    [, $handover] = readyHandover();
    $officer = registryOfficer();
    $handover = app(HandoverWorkflowAction::class)->markDocumentsReady($handover, $officer);

    $data = ['handover_date' => now()->toDateString(), 'received_by' => 'Ramesh'];
    $first = app(HandoverWorkflowAction::class)->complete($handover->fresh(), $data, $officer);
    $again = app(HandoverWorkflowAction::class)->complete($handover->fresh(), $data, $officer);

    expect($again->completed_at->equalTo($first->completed_at))->toBeTrue()
        ->and(DocumentHandover::where('booking_id', $handover->booking_id)->count())->toBe(1);
});

it('reverses a completed handover through the dedicated workflow', function () {
    [, $handover] = readyHandover();
    $officer = registryOfficer();
    $handover = app(HandoverWorkflowAction::class)->markDocumentsReady($handover, $officer);
    $handover = app(HandoverWorkflowAction::class)->complete($handover->fresh(), [
        'handover_date' => now()->toDateString(), 'received_by' => 'Ramesh',
    ], $officer);

    $handover = app(HandoverWorkflowAction::class)->reverse($handover->fresh(), 'Wrong recipient', $officer);

    expect($handover->status)->toBe(HandoverStatus::DocumentsReady)
        ->and($handover->reversal_reason)->toBe('Wrong recipient')
        ->and($handover->reversed_by)->toBe($officer->id);
});
