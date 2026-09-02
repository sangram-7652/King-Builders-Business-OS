<?php

declare(strict_types=1);

use App\Actions\Possession\GeneratePossessionCertificateAction;
use App\Actions\Possession\PossessionCaseWorkflowAction;
use App\Actions\Possession\PossessionHandoverAction;
use App\Enums\PlotStatus;
use App\Enums\PossessionCaseStatus;
use App\Enums\PossessionHandoverStatus;
use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\PossessionHandover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

function handoverReadyCase(): array
{
    $s = scheduledPossessionScenario();
    $officer = possessionOfficer();
    $case = app(PossessionCaseWorkflowAction::class)->markReadyForHandover($s['case']->fresh(), $officer);

    return [$s['booking'], $case->fresh('handover'), $officer];
}

/*
| POSSESSION HANDOVER (12-13) + CERTIFICATE (14)
*/

it('walks ready-for-handover → handover → acknowledgement → completed (12)', function () {
    [$booking, $case, $officer] = handoverReadyCase();
    $handover = $case->handover;

    expect($handover->status)->toBe(PossessionHandoverStatus::ReadyForHandover);

    $handover = app(PossessionHandoverAction::class)->startHandover($handover, [
        'handover_date' => now()->toDateString(),
        'received_by' => 'Sita Devi',
        'receiver_identity' => 'AADHAAR ****1234',
        'receiver_relation' => 'self',
    ], $officer);
    expect($handover->status)->toBe(PossessionHandoverStatus::Handover)
        ->and($handover->received_by)->toBe('Sita Devi');

    $handover = app(PossessionHandoverAction::class)->recordAcknowledgement($handover->fresh(), $officer, fakeDocument('ack.pdf'));
    expect($handover->status)->toBe(PossessionHandoverStatus::Acknowledgement)
        ->and($handover->acknowledgement_document_id)->not->toBeNull();

    $handover = app(PossessionHandoverAction::class)->complete($handover->fresh(), $officer);

    expect($handover->status)->toBe(PossessionHandoverStatus::Completed)
        ->and($handover->possessionCase->status)->toBe(PossessionCaseStatus::Completed)
        ->and($booking->plot->fresh()->status)->toBe(PlotStatus::PossessionCompleted);
});

it('requires received_by and possession.complete to start a handover', function () {
    [, $case] = handoverReadyCase();

    expect(fn () => app(PossessionHandoverAction::class)->startHandover($case->handover, [
        'handover_date' => now()->toDateString(), 'received_by' => '  ',
    ], possessionOfficer()))->toThrow(DomainException::class);

    $noComplete = makeUser(permissions: ['possession.view', 'possession.schedule', 'bookings.view']);
    expect(fn () => app(PossessionHandoverAction::class)->startHandover($case->handover, [
        'handover_date' => now()->toDateString(), 'received_by' => 'X',
    ], $noComplete))->toThrow(DomainException::class);
});

it('never records a duplicate completed handover — completion is idempotent (13)', function () {
    [, $case, $officer] = handoverReadyCase();
    $handover = $case->handover;

    app(PossessionHandoverAction::class)->startHandover($handover, ['handover_date' => now()->toDateString(), 'received_by' => 'A'], $officer);
    app(PossessionHandoverAction::class)->recordAcknowledgement($handover->fresh(), $officer);
    $first = app(PossessionHandoverAction::class)->complete($handover->fresh(), $officer);
    $again = app(PossessionHandoverAction::class)->complete($handover->fresh(), $officer);

    expect($again->completed_at->equalTo($first->completed_at))->toBeTrue()
        ->and(PossessionHandover::where('possession_case_id', $case->id)->count())->toBe(1);
});

it('generates the possession certificate as a versioned document and is idempotent (14)', function () {
    [$booking, $case, $officer] = handoverReadyCase();
    $handover = $case->handover;
    app(PossessionHandoverAction::class)->startHandover($handover, ['handover_date' => now()->toDateString(), 'received_by' => 'A'], $officer);
    app(PossessionHandoverAction::class)->recordAcknowledgement($handover->fresh(), $officer);
    app(PossessionHandoverAction::class)->complete($handover->fresh(), $officer);

    $doc = app(GeneratePossessionCertificateAction::class)->handle($case->fresh(), $officer);

    expect($doc)->toBeInstanceOf(Document::class)
        ->and($doc->versions()->count())->toBe(1)
        ->and($case->fresh()->certificate_document_id)->toBe($doc->id);

    $again = app(GeneratePossessionCertificateAction::class)->handle($case->fresh(), $officer);
    expect($again->id)->toBe($doc->id)
        ->and($again->versions()->count())->toBe(1);
});

it('cannot generate a certificate before possession completes', function () {
    [, $case, $officer] = handoverReadyCase();

    expect(fn () => app(GeneratePossessionCertificateAction::class)->handle($case->fresh(), $officer))
        ->toThrow(DomainException::class);
});
