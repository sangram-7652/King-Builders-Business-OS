<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocumentAction;
use App\Actions\Documents\VerifyDocumentAction;
use App\Enums\DocumentScope;
use App\Enums\PartnerActivityType;
use App\Livewire\Partners\PartnerDocuments;
use App\Models\Document;
use App\Models\Partner;
use App\Models\User;
use App\Services\Documents\DocumentChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

/** A user who can manage channel partners and their KYC. */
function partnerOfficer(): User
{
    return makeUser(permissions: [
        'partners.view', 'partners.create', 'partners.update', 'partners.approve', 'partners.authorize',
        'documents.view', 'documents.upload', 'documents.verify', 'documents.reject', 'documents.download',
    ]);
}

it('seeds partner-scoped KYC document types', function () {
    expect(docType('PARTNER_PAN')->applies_to)->toBe(DocumentScope::Partner)
        ->and(docType('PARTNER_AGREEMENT')->applies_to)->toBe(DocumentScope::Partner);
});

it('builds a partner KYC checklist from the partner-scoped requirements', function () {
    $checklist = app(DocumentChecklistService::class)->forPartner(Partner::factory()->active()->create());

    // No document type is required CRM-wide, so the checklist lists the partner-scoped
    // slots but nothing is required, and it is vacuously complete.
    expect($checklist->items)->not->toBeEmpty()
        ->and($checklist->requiredCount)->toBe(0)
        ->and($checklist->verifiedCount)->toBe(0)
        ->and($checklist->isComplete())->toBeTrue();
});

it('stores partner KYC documents on the private documents disk', function () {
    $partner = Partner::factory()->active()->create();
    $actor = partnerOfficer();

    $doc = app(UploadDocumentAction::class)->handle($partner, docType('PARTNER_PAN'), fakeDocument('pan.pdf'), $actor);

    expect($doc->documentable_type)->toBe($partner->getMorphClass())
        ->and($doc->currentVersion->disk)->toBe('documents');
    Storage::disk('documents')->assertExists($doc->currentVersion->path);
});

it('records a partner activity when a KYC document is uploaded and verified through the screen', function () {
    $partner = Partner::factory()->active()->create();
    $actor = partnerOfficer();

    Livewire::actingAs($actor)
        ->test(PartnerDocuments::class, ['partner' => $partner])
        ->set('files.'.docType('PARTNER_PAN')->id, fakeDocument('pan.pdf'))
        ->assertHasNoErrors();

    $doc = Document::query()->where('documentable_id', $partner->id)->firstOrFail();

    Livewire::actingAs($actor)
        ->test(PartnerDocuments::class, ['partner' => $partner])
        ->call('verify', $doc->id)
        ->assertHasNoErrors();

    expect($partner->fresh()->activities()->where('type', PartnerActivityType::KycUploaded->value)->exists())->toBeTrue()
        ->and($partner->fresh()->activities()->where('type', PartnerActivityType::KycVerified->value)->exists())->toBeTrue()
        ->and($doc->fresh()->isVerified())->toBeTrue();
});

it('blocks KYC document download for a user who cannot view the partner (IDOR)', function () {
    $partner = Partner::factory()->active()->create();
    $doc = app(UploadDocumentAction::class)->handle($partner, docType('PARTNER_PAN'), fakeDocument(), partnerOfficer());

    // Has documents.download but NOT partners.view → cannot reach the partner.
    $outsider = makeUser(permissions: ['documents.view', 'documents.download']);

    $this->actingAs($outsider)
        ->get(route('documents.download', ['document' => $doc->id, 'version' => $doc->current_version_id]))
        ->assertForbidden();
});

it('allows KYC document download for a partner officer', function () {
    $partner = Partner::factory()->active()->create();
    $actor = partnerOfficer();
    $doc = app(UploadDocumentAction::class)->handle($partner, docType('PARTNER_PAN'), fakeDocument(), $actor);
    app(VerifyDocumentAction::class)->handle($doc, $actor);

    $this->actingAs($actor)
        ->get(route('documents.download', ['document' => $doc->id, 'version' => $doc->current_version_id]))
        ->assertOk();
});
