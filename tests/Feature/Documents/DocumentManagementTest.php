<?php

declare(strict_types=1);

use App\Actions\Documents\DeleteDocumentAction;
use App\Actions\Documents\ExpireDocumentsAction;
use App\Actions\Documents\RejectDocumentAction;
use App\Actions\Documents\SubmitDocumentForReviewAction;
use App\Actions\Documents\UploadDocumentAction;
use App\Actions\Documents\VerifyDocumentAction;
use App\Enums\DocumentStatus;
use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\DocumentVersion;
use App\Services\Documents\DocumentChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

/*
| DOCUMENTS
*/

it('creates one document row with version 1 on first upload (1)', function () {
    $s = confirmedBookingScenario();
    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], docType('AADHAAR'), fakeDocument('aadhaar.pdf'), $s['actor']);

    expect($doc->status)->toBe(DocumentStatus::Uploaded)
        ->and($doc->versions()->count())->toBe(1)
        ->and($doc->currentVersion->version)->toBe(1)
        ->and(Document::where('documentable_id', $s['buyer']->id)->count())->toBe(1);

    Storage::disk('documents')->assertExists($doc->currentVersion->path);
});

it('adds a new version and keeps the previous file on re-upload (2, 7)', function () {
    $s = confirmedBookingScenario();
    $type = docType('AADHAAR');

    $v1 = app(UploadDocumentAction::class)->handle($s['buyer'], $type, fakeDocument('a1.pdf'), $s['actor']);
    $firstPath = $v1->currentVersion->path;

    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], $type, fakeDocument('a2.pdf'), $s['actor']);

    expect($doc->id)->toBe($v1->id)
        ->and($doc->versions()->count())->toBe(2)
        ->and($doc->currentVersion->version)->toBe(2);

    // the earlier version is never destroyed
    Storage::disk('documents')->assertExists($firstPath);
    expect(DocumentVersion::where('document_id', $doc->id)->where('version', 1)->exists())->toBeTrue();
});

it('treats an identical re-upload as idempotent — no new version', function () {
    $s = confirmedBookingScenario();
    $type = docType('PAN');
    $file = fakeDocument('pan.pdf', '%PDF fixed bytes for idempotency');

    app(UploadDocumentAction::class)->handle($s['buyer'], $type, $file, $s['actor']);
    $doc = app(UploadDocumentAction::class)->handle(
        $s['buyer'], $type,
        fakeDocument('pan-again.pdf', '%PDF fixed bytes for idempotency'),
        $s['actor'],
    );

    expect($doc->versions()->count())->toBe(1);
});

it('verifies an uploaded document and records who / when (4)', function () {
    $s = confirmedBookingScenario();
    $officer = registryOfficer();
    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], docType('PAN'), fakeDocument(), $s['actor']);

    $doc = app(VerifyDocumentAction::class)->handle($doc, $officer);

    expect($doc->status)->toBe(DocumentStatus::Verified)
        ->and($doc->verified_by)->toBe($officer->id)
        ->and($doc->verified_at)->not->toBeNull();
});

it('refuses to verify without a stored file', function () {
    $s = confirmedBookingScenario();
    $doc = Document::factory()->forDocumentable($s['buyer'])->state([
        'document_type_id' => docType('PAN')->id, 'status' => DocumentStatus::Pending->value,
    ])->create();

    expect(fn () => app(VerifyDocumentAction::class)->handle($doc, registryOfficer()))
        ->toThrow(DomainException::class);
});

it('requires a reason to reject and stores it (5)', function () {
    $s = confirmedBookingScenario();
    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], docType('PAN'), fakeDocument(), $s['actor']);

    expect(fn () => app(RejectDocumentAction::class)->handle($doc, '   ', registryOfficer()))
        ->toThrow(DomainException::class);

    $doc = app(RejectDocumentAction::class)->handle($doc, 'Blurred scan', registryOfficer());
    expect($doc->status)->toBe(DocumentStatus::Rejected)
        ->and($doc->rejection_reason)->toBe('Blurred scan');
});

it('moves a rejected document back to uploaded on re-upload (6)', function () {
    $s = confirmedBookingScenario();
    $type = docType('PAN');
    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], $type, fakeDocument('r1.pdf'), $s['actor']);
    app(RejectDocumentAction::class)->handle($doc, 'Wrong document', registryOfficer());

    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], $type, fakeDocument('r2.pdf'), $s['actor']);

    expect($doc->fresh()->status)->toBe(DocumentStatus::Uploaded)
        ->and($doc->fresh()->rejection_reason)->toBeNull()
        ->and($doc->versions()->count())->toBe(2);
});

it('re-uploading a verified document keeps the verified file as a version and resets status (7)', function () {
    $s = confirmedBookingScenario();
    $type = docType('PAN');
    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], $type, fakeDocument('v1.pdf'), $s['actor']);
    app(VerifyDocumentAction::class)->handle($doc, registryOfficer());

    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], $type, fakeDocument('v2.pdf'), $s['actor']);

    expect($doc->fresh()->status)->toBe(DocumentStatus::Uploaded)
        ->and($doc->fresh()->verified_by)->toBeNull()
        ->and($doc->versions()->count())->toBe(2);
});

it('submits an uploaded document for review', function () {
    $s = confirmedBookingScenario();
    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], docType('PAN'), fakeDocument(), $s['actor']);

    $doc = app(SubmitDocumentForReviewAction::class)->handle($doc, $s['actor']);
    expect($doc->status)->toBe(DocumentStatus::UnderReview);

    // idempotent
    expect(app(SubmitDocumentForReviewAction::class)->handle($doc, $s['actor'])->status)
        ->toBe(DocumentStatus::UnderReview);
});

it('protects a verified document from deletion (9)', function () {
    $s = confirmedBookingScenario();
    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], docType('PAN'), fakeDocument(), $s['actor']);
    app(VerifyDocumentAction::class)->handle($doc, registryOfficer());

    expect(fn () => app(DeleteDocumentAction::class)->handle($doc->fresh(), registryOfficer()))
        ->toThrow(DomainException::class);

    expect(Document::whereKey($doc->id)->exists())->toBeTrue();
});

it('soft-deletes a non-protected document', function () {
    $s = confirmedBookingScenario();
    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], docType('PAN'), fakeDocument(), $s['actor']);

    app(DeleteDocumentAction::class)->handle($doc, registryOfficer());

    expect(Document::whereKey($doc->id)->exists())->toBeFalse()
        ->and(Document::withTrashed()->whereKey($doc->id)->exists())->toBeTrue();
});

it('marks documents past their expiry as expired (10)', function () {
    $s = confirmedBookingScenario();
    $doc = app(UploadDocumentAction::class)->handle(
        $s['buyer'], docType('ADDRESS_PROOF'), fakeDocument(), $s['actor'],
        ['expires_at' => now()->subDay()->toDateString()],
    );
    app(VerifyDocumentAction::class)->handle($doc, registryOfficer());

    $count = app(ExpireDocumentsAction::class)->handle(Carbon::today());

    expect($count)->toBe(1)
        ->and($doc->fresh()->status)->toBe(DocumentStatus::Expired);

    // idempotent
    expect(app(ExpireDocumentsAction::class)->handle(Carbon::today()))->toBe(0);
});

/*
| CHECKLIST / COMPLETENESS (8)
*/

it('derives checklist counts and never stores a percentage (8)', function () {
    $s = confirmedBookingScenario();

    $result = app(DocumentChecklistService::class)->forBuyer($s['buyer']);
    expect($result->requiredCount)->toBe(4) // AADHAAR, PAN, ADDRESS_PROOF, PHOTO
        ->and($result->verifiedCount)->toBe(0)
        ->and($result->isComplete())->toBeFalse();

    foreach (['AADHAAR', 'PAN', 'ADDRESS_PROOF', 'PHOTO'] as $code) {
        $doc = app(UploadDocumentAction::class)->handle($s['buyer'], docType($code), fakeDocument("$code.pdf"), $s['actor']);
        app(VerifyDocumentAction::class)->handle($doc, registryOfficer());
    }

    $result = app(DocumentChecklistService::class)->forBuyer($s['buyer']->fresh());
    expect($result->verifiedCount)->toBe(4)
        ->and($result->receivedCount)->toBe(4)
        ->and($result->isComplete())->toBeTrue();

    // completion is not persisted anywhere on the buyer / documents
    expect(Schema::hasColumn('documents', 'completion_percent'))->toBeFalse();
});

it('lets a project-specific requirement override the global default', function () {
    $s = confirmedBookingScenario();
    $other = confirmedBookingScenario();

    // globally REGISTRY_DOC is optional; make it required for this project only
    DocumentRequirement::create([
        'project_id' => $s['booking']->project_id,
        'document_type_id' => docType('REGISTRY_DOC')->id,
        'applies_to' => 'booking',
        'required' => true,
        'sequence' => 9,
        'is_active' => true,
    ]);

    // BOOKING_FORM + BOOKING_AGREEMENT globally, + REGISTRY_DOC for this project
    expect(app(DocumentChecklistService::class)->forBooking($s['booking'])->requiredCount)->toBe(3)
        ->and(app(DocumentChecklistService::class)->forBooking($other['booking'])->requiredCount)->toBe(2);
});
