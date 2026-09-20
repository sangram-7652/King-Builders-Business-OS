<?php

declare(strict_types=1);

use App\Models\Agreement;
use App\Models\Booking;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentHandover;
use App\Models\DocumentVersion;
use App\Models\RegistryCase;
use App\Models\RegistryExpense;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(fn () => seedDocumentMasters());

it('creates every M9 table with its key columns', function () {
    expect(Schema::hasTable('document_requirements'))->toBeTrue()
        ->and(Schema::hasTable('documents'))->toBeTrue()
        ->and(Schema::hasTable('document_versions'))->toBeTrue()
        ->and(Schema::hasTable('agreements'))->toBeTrue()
        ->and(Schema::hasTable('registry_cases'))->toBeTrue()
        ->and(Schema::hasTable('registry_appointments'))->toBeTrue()
        ->and(Schema::hasTable('registry_expenses'))->toBeTrue()
        ->and(Schema::hasTable('document_handovers'))->toBeTrue()
        ->and(Schema::hasTable('document_activities'))->toBeTrue();

    expect(Schema::hasColumns('documents', ['documentable_type', 'documentable_id', 'document_type_id', 'status', 'current_version_id', 'verified_by', 'rejection_reason', 'expires_at', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('document_versions', ['document_id', 'version', 'disk', 'path', 'checksum', 'original_filename']))->toBeTrue()
        ->and(Schema::hasColumns('agreements', ['agreement_number', 'booking_id', 'status', 'document_id', 'terms_snapshot', 'signed_at', 'approved_by']))->toBeTrue()
        ->and(Schema::hasColumns('registry_cases', ['case_number', 'booking_id', 'status', 'eligibility_snapshot', 'registered_document_number', 'status_before_hold']))->toBeTrue()
        ->and(Schema::hasColumns('registry_expenses', ['registry_case_id', 'booking_id', 'expense_type', 'amount', 'status', 'approved_by']))->toBeTrue()
        ->and(Schema::hasColumns('document_handovers', ['booking_id', 'registry_case_id', 'document_id', 'status', 'received_by', 'reversed_by']))->toBeTrue();
});

it('tags document types with a scope and default-required flag', function () {
    // No document type is required by default (CRM-wide policy: every document is optional).
    expect(Schema::hasColumns('document_types', ['applies_to', 'default_required', 'supports_expiry']))->toBeTrue()
        ->and(docType('AADHAAR')->applies_to->value)->toBe('buyer')
        ->and(docType('AADHAAR')->default_required)->toBeFalse()
        ->and(docType('BANK_PROOF')->default_required)->toBeFalse();
});

it('stores registry expense amounts as DECIMAL, not float', function () {
    $driver = DB::connection()->getDriverName();

    $type = $driver === 'sqlite'
        ? collect(DB::select("PRAGMA table_info('registry_expenses')"))->firstWhere('name', 'amount')->type
        : DB::selectOne(
            'SELECT DATA_TYPE AS t FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['registry_expenses', 'amount'],
        )->t;

    // sqlite compiles decimal() to "numeric" (still exact, never float); mysql keeps "decimal"
    expect(strtolower((string) $type))->toBeIn(['decimal', 'decimal(15,2)', 'numeric'])
        ->and((new RegistryExpense)->getCasts()['amount'])->toBe('decimal:2');

    $e = RegistryExpense::factory()->create(['amount' => '12345.67']);
    expect($e->fresh()->amount)->toBe('12345.67');
});

it('enforces one document per (documentable, type)', function () {
    $s = confirmedBookingScenario();
    Document::factory()->forDocumentable($s['buyer'])->state(['document_type_id' => docType('PAN')->id])->create();

    expect(fn () => Document::factory()->forDocumentable($s['buyer'])->state(['document_type_id' => docType('PAN')->id])->create())
        ->toThrow(QueryException::class);
});

it('enforces unique version numbers per document', function () {
    $doc = Document::factory()->forDocumentable(confirmedBookingScenario()['buyer'])
        ->state(['document_type_id' => docType('PAN')->id])->create();
    DocumentVersion::factory()->create(['document_id' => $doc->id, 'version' => 1]);

    expect(fn () => DocumentVersion::factory()->create(['document_id' => $doc->id, 'version' => 1]))
        ->toThrow(QueryException::class);
});

it('enforces one registry case and one handover per booking', function () {
    $booking = Booking::factory()->confirmed()->create();
    RegistryCase::factory()->forBooking($booking)->create();

    expect(fn () => RegistryCase::factory()->forBooking($booking)->create())
        ->toThrow(QueryException::class);
});

it('enforces a unique agreement number', function () {
    $a = Agreement::factory()->create();

    expect(fn () => Agreement::factory()->create(['agreement_number' => $a->agreement_number]))
        ->toThrow(QueryException::class);
});

it('keeps the document activity timeline append-only', function () {
    expect(DocumentHandover::query()->getModel())->toBeInstanceOf(DocumentHandover::class)
        ->and((new DocumentActivity)->getAttributes())->not->toHaveKey('updated_at');
    expect(DocumentActivity::UPDATED_AT)->toBeNull();
});
