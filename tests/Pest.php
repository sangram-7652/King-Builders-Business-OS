<?php

declare(strict_types=1);

use App\Actions\Commission\GenerateCommissionCases;
use App\Actions\Customers\ActivateCustomerPortal;
use App\Actions\Customers\InviteCustomerToPortal;
use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Actions\Possession\InitiatePossessionCaseAction;
use App\Actions\Possession\RecordClearanceAction;
use App\Actions\Possession\RecordInspectionAction;
use App\Actions\Possession\SchedulePossessionAppointmentAction;
use App\Enums\ClearanceStatus;
use App\Enums\CommunicationCategory;
use App\Enums\CommunicationChannel;
use App\Enums\DatePreset;
use App\Enums\InspectionStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Enums\RegistryCaseStatus;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\Block;
use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use App\Models\CommissionCase;
use App\Models\CommissionScheme;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Models\Masters\PaymentMode;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Plot;
use App\Models\PossessionCase;
use App\Models\Project;
use App\Models\RegistryCase;
use App\Models\User;
use App\Services\Communication\CommunicationRequest;
use App\Services\Reports\MisAnalytics;
use App\Support\Reports\ReportFilterData;
use Carbon\CarbonImmutable;
use Database\Seeders\Masters\DocumentRequirementSeeder;
use Database\Seeders\Masters\DocumentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
| ---------------------------------------------------------------------------
| Test Case bindings
| ---------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/*
| ---------------------------------------------------------------------------
| RBAC helpers
| ---------------------------------------------------------------------------
*/

function seedRbac(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
}

/**
 * Create a user, optionally with roles and/or explicit permissions.
 *
 * @param  array<int, string>  $roles
 * @param  array<int, string>  $permissions
 */
function makeUser(array $roles = [], array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    if ($roles !== []) {
        $user->syncRoles($roles);
    }

    if ($permissions !== []) {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

function superAdmin(): User
{
    seedRbac();

    return makeUser([RoleName::SuperAdmin->value]);
}

/**
 * A user holding the full masters.* permission set (no other access).
 */
function masterAdmin(): User
{
    return makeUser(permissions: [
        'masters.view', 'masters.create', 'masters.update', 'masters.delete',
    ]);
}

/**
 * A user holding the full projects.* permission set (no other access).
 */
function projectManager(): User
{
    return makeUser(permissions: [
        'projects.view', 'projects.create', 'projects.update',
        'projects.delete', 'projects.activate', 'projects.archive',
    ]);
}

/**
 * A user holding the full plots.* permission set (no other access).
 */
function plotManager(): User
{
    return makeUser(permissions: [
        'plots.view', 'plots.create', 'plots.update', 'plots.delete',
        'plots.hold', 'plots.release', 'plots.activate', 'plots.archive',
        'plots.bulk_create',
    ]);
}

/**
 * Full buyers.* including archive and KYC access.
 */
function buyerManager(): User
{
    return makeUser(permissions: [
        'buyers.view', 'buyers.create', 'buyers.update', 'buyers.delete',
        'buyers.archive', 'buyers.documents',
    ]);
}

/**
 * Full bookings.* + pricing.* including confirm, cancel, delete and override.
 */
function bookingManager(): User
{
    return makeUser(permissions: [
        'bookings.view', 'bookings.create', 'bookings.update', 'bookings.confirm',
        'bookings.cancel', 'bookings.delete',
        'pricing.view', 'pricing.manage', 'pricing.override',
        'plots.view', 'buyers.view',
    ]);
}

/**
 * A booking clerk — can build and edit bookings but not confirm, cancel,
 * delete or override pricing.
 */
function bookingClerk(): User
{
    return makeUser(permissions: [
        'bookings.view', 'bookings.create', 'bookings.update',
        'pricing.view',
        'plots.view', 'buyers.view',
    ]);
}

/**
 * Full payments.* + receipts.* (finance manager).
 */
function financeManager(): User
{
    return makeUser(permissions: [
        'payments.view', 'payments.create', 'payments.verify', 'payments.reverse',
        'receipts.view', 'receipts.generate',
        'bookings.view', 'buyers.view',
    ]);
}

/**
 * A cashier — records payments and views, but cannot verify or reverse.
 */
function cashier(): User
{
    return makeUser(permissions: [
        'payments.view', 'payments.create', 'receipts.view',
        'bookings.view',
    ]);
}

/*
| ---------------------------------------------------------------------------
| Booking scenario builders (M6)
| ---------------------------------------------------------------------------
*/

/**
 * A ready-to-book project → block → AVAILABLE plot plus two active buyers.
 *
 * @return array{actor: User, project: Project, block: Block, plot: Plot, buyerA: Buyer, buyerB: Buyer}
 */
function bookingScenario(array $plotAttributes = []): array
{
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $plot = Plot::factory()->create(array_merge([
        'project_id' => $project->id,
        'block_id' => $block->id,
        'status' => PlotStatus::Available->value,
        'area' => 1000,
    ], $plotAttributes));

    return [
        'actor' => User::factory()->create(),
        'project' => $project,
        'block' => $block,
        'plot' => $plot,
        'buyerA' => Buyer::factory()->create(['status' => 'active']),
        'buyerB' => Buyer::factory()->create(['status' => 'active']),
    ];
}

/**
 * @param  array<string, mixed>  $s  a bookingScenario()
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bookingPayload(array $s, array $overrides = []): array
{
    $base = [
        'project_id' => $s['project']->id,
        'block_id' => $s['block']->id,
        'plot_id' => $s['plot']->id,
        'booking_date' => now()->toDateString(),
        'notes' => null,
        'pricing' => [
            'base_area' => '1000',
            'base_rate' => '2000',
            'components' => [
                ['type' => 'tax', 'name' => 'GST', 'calculation_type' => 'percentage', 'rate' => '5'],
            ],
        ],
        'buyers' => [
            ['buyer_id' => $s['buyerA']->id, 'ownership_percentage' => '100', 'is_primary' => true],
        ],
    ];

    $payload = array_merge($base, $overrides);

    if (isset($overrides['pricing'])) {
        $payload['pricing'] = array_merge($base['pricing'], $overrides['pricing']);
    }

    return $payload;
}

/*
| ---------------------------------------------------------------------------
| Payment scenario builders (M7)
| ---------------------------------------------------------------------------
*/

/**
 * A CONFIRMED booking (final_amount = ₹1,000,000) with a BOOKED plot and one
 * primary buyer, plus an actor. Ready to record a direct payment against.
 *
 * @return array{actor: User, booking: Booking, buyer: Buyer}
 */
function confirmedBookingScenario(string $finalAmount = '1000000'): array
{
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $plot = Plot::factory()->create([
        'project_id' => $project->id,
        'block_id' => $block->id,
        'status' => PlotStatus::Booked->value,
    ]);

    $booking = Booking::factory()->confirmed()->forPlot($plot)->create([
        'final_amount' => $finalAmount,
        'base_amount' => $finalAmount,
        'subtotal' => $finalAmount,
    ]);

    $buyer = Buyer::factory()->create(['status' => 'active']);
    BookingBuyer::factory()->create([
        'booking_id' => $booking->id,
        'buyer_id' => $buyer->id,
        'ownership_percentage' => 100,
        'is_primary' => true,
    ]);

    return ['actor' => User::factory()->create(), 'booking' => $booking, 'buyer' => $buyer];
}

/**
 * The CASH payment mode (seeded, or created for the test).
 */
function cashMode(): PaymentMode
{
    return PaymentMode::query()->firstOrCreate(
        ['code' => 'CASH'],
        ['name' => 'Cash', 'requires_reference' => false, 'is_cheque' => false, 'is_active' => true, 'is_system' => true, 'sort_order' => 0],
    );
}

function chequeMode(): PaymentMode
{
    return PaymentMode::query()->firstOrCreate(
        ['code' => 'CHEQUE'],
        ['name' => 'Cheque', 'requires_reference' => true, 'is_cheque' => true, 'is_active' => true, 'is_system' => true, 'sort_order' => 1],
    );
}

/** Record + verify a direct CASH payment against a booking (no payment plan). */
function payIn(Booking $booking, User $actor, string $amount, string $date): Payment
{
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $booking->id, 'payment_mode_id' => cashMode()->id, 'amount' => $amount, 'payment_date' => $date,
    ], $actor);

    return app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $actor);
}

/*
| ---------------------------------------------------------------------------
| Documentation / Registry helpers (M9)
| ---------------------------------------------------------------------------
*/

/** Seed the document-type masters + their global checklist requirements. */
function seedDocumentMasters(): void
{
    (new DocumentTypeSeeder)->run();
    (new DocumentRequirementSeeder)->run();
}

function docType(string $code): DocumentType
{
    return DocumentType::query()->where('code', $code)->firstOrFail();
}

/** Full documents.* + agreements.* + registry.* + registry_expenses.* + handover.* (+ view of buyers/bookings). */
function registryOfficer(): User
{
    return makeUser(permissions: [
        'documents.view', 'documents.upload', 'documents.verify', 'documents.reject', 'documents.download', 'documents.delete',
        'agreements.view', 'agreements.create', 'agreements.update', 'agreements.approve',
        'registry.view', 'registry.create', 'registry.update', 'registry.schedule', 'registry.complete',
        'registry_expenses.view', 'registry_expenses.create', 'registry_expenses.approve',
        'handover.view', 'handover.create', 'handover.complete',
        'buyers.view', 'bookings.view', 'buyers.documents',
    ]);
}

/** A fake PDF UploadedFile with unique content, so each upload has a distinct sha256 checksum. */
function fakeDocument(string $name = 'scan.pdf', ?string $content = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        $content ?? ('%PDF-1.4 '.bin2hex(random_bytes(64))),
    );
}

/**
 * A confirmed booking whose registry eligibility passes: required buyer KYC +
 * booking documents verified, agreement signed, payment threshold relaxed.
 *
 * @return array{actor: User, booking: Booking, buyer: Buyer}
 */
function registryReadyScenario(string $finalAmount = '1000000'): array
{
    seedDocumentMasters();
    config()->set('registry.eligibility.required_paid_percent', 0);

    $s = confirmedBookingScenario($finalAmount);

    foreach (['AADHAAR', 'PAN', 'ADDRESS_PROOF', 'PHOTO'] as $code) {
        Document::factory()->verified()->forDocumentable($s['buyer'])
            ->state(['document_type_id' => docType($code)->id])->create();
    }

    foreach (['BOOKING_FORM', 'BOOKING_AGREEMENT'] as $code) {
        Document::factory()->verified()->forDocumentable($s['booking'])
            ->state(['document_type_id' => docType($code)->id])->create();
    }

    Agreement::factory()->signed()->create(['booking_id' => $s['booking']->id]);

    return $s;
}

/*
| ---------------------------------------------------------------------------
| Possession / Transfer / Ownership helpers (M10)
| ---------------------------------------------------------------------------
*/

/** Full possession.* + transfer.* + ownership.view (+ documents / view of buyers/bookings). */
function possessionOfficer(): User
{
    return makeUser(permissions: [
        'possession.view', 'possession.create', 'possession.schedule', 'possession.inspect', 'possession.complete', 'possession.clear',
        'transfer.view', 'transfer.create', 'transfer.review', 'transfer.approve', 'transfer.complete',
        'ownership.view',
        'documents.view', 'documents.upload', 'documents.verify', 'documents.download',
        'plots.view', 'buyers.view', 'bookings.view', 'buyers.documents',
    ]);
}

/**
 * A confirmed booking whose possession eligibility passes: registry COMPLETED,
 * required booking documents verified, no overdue, payment threshold relaxed.
 *
 * @return array{actor: User, booking: Booking, buyer: Buyer}
 */
function possessionReadyScenario(string $finalAmount = '1000000'): array
{
    $s = registryReadyScenario($finalAmount);

    config()->set('possession.eligibility.required_paid_percent', 0);
    // Relax the financial-clearance guard for scenario setup; the dedicated
    // "reads M7/M8 truth" test tightens it again explicitly.
    config()->set('possession.financial_clearance.max_outstanding', '100000000');

    RegistryCase::factory()
        ->forBooking($s['booking'])
        ->status(RegistryCaseStatus::Completed)
        ->create(['registered_document_number' => 'RD-'.fake()->numerify('#####')]);

    return $s;
}

/**
 * A possession case at READY, with all clearances CLEARED and a PASSED
 * inspection — i.e. one `markReadyForHandover()` away from handover.
 *
 * @return array{actor: User, booking: Booking, buyer: Buyer, case: PossessionCase}
 */
function scheduledPossessionScenario(): array
{
    $s = possessionReadyScenario();
    $officer = possessionOfficer();

    $case = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), $officer);

    foreach ($case->clearances as $clearance) {
        app(RecordClearanceAction::class)->handle(
            $case, $clearance->category, ClearanceStatus::Cleared, $officer,
        );
    }

    $case = app(SchedulePossessionAppointmentAction::class)->handle($case->fresh(), [
        'scheduled_at' => now()->addWeek()->toDateTimeString(),
        'site_location' => 'Site office',
    ], $officer);

    app(RecordInspectionAction::class)->handle($case->fresh(), [
        'status' => InspectionStatus::Passed->value,
        'inspection_date' => now()->toDateString(),
    ], $officer);

    return $s + ['case' => $case->fresh(['clearances', 'latestInspection', 'handover'])];
}

/**
 * A confirmed booking ready for an ownership transfer: the incoming buyer
 * exists, the required transfer documents are verified and the financial gate
 * is relaxed.
 *
 * @return array{actor: User, booking: Booking, buyer: Buyer, newBuyer: Buyer}
 */
function transferReadyScenario(string $finalAmount = '1000000'): array
{
    seedDocumentMasters();
    config()->set('transfer.financial.block_on_outstanding', false);

    $s = confirmedBookingScenario($finalAmount);
    $newBuyer = Buyer::factory()->create(['status' => 'active']);

    foreach (['TRANSFER_APPLICATION', 'TRANSFER_CONSENT', 'TRANSFER_ID_PROOF'] as $code) {
        Document::factory()->verified()->forDocumentable($s['booking'])
            ->state(['document_type_id' => docType($code)->id])->create();
    }

    return $s + ['newBuyer' => $newBuyer];
}

/*
| ---------------------------------------------------------------------------
| Reporting — MIS + export helpers (M11.5)
| ---------------------------------------------------------------------------
*/

/**
 * Two projects with confirmed bookings, one with a partial payment. Shared by
 * the MIS and export tests.
 *
 *   Alpha Estate / A1 : 6 plots (4 avail, 2 booked) · booking A ₹10 L · sp Asha
 *       ₹3 L paid (cash 09-12), ₹7 L outstanding
 *   Beta Park / B1    : 4 plots (3 avail, 1 booked) · booking B ₹20 L · sp Ravi
 *       unpaid, ₹20 L outstanding
 *
 * @return array<string, mixed>
 */
function misWorld(): array
{
    $actor = User::factory()->create();
    $asha = User::factory()->create(['name' => 'Asha Rao']);
    $ravi = User::factory()->create(['name' => 'Ravi Menon']);

    $alpha = Project::factory()->create(['name' => 'Alpha Estate']);
    $a1 = Block::factory()->create(['project_id' => $alpha->id, 'name' => 'Block A1']);
    $beta = Project::factory()->create(['name' => 'Beta Park']);
    $b1 = Block::factory()->create(['project_id' => $beta->id, 'name' => 'Block B1']);

    // Alpha: 4 available + 1 booked + booking-A's plot = 6 total / 4 avail / 2 booked
    // Beta:  3 available + booking-B's plot            = 4 total / 3 avail / 1 booked
    Plot::factory()->count(4)->create(['project_id' => $alpha->id, 'block_id' => $a1->id, 'status' => PlotStatus::Available->value]);
    Plot::factory()->create(['project_id' => $alpha->id, 'block_id' => $a1->id, 'status' => PlotStatus::Booked->value]);
    Plot::factory()->count(3)->create(['project_id' => $beta->id, 'block_id' => $b1->id, 'status' => PlotStatus::Available->value]);

    $mk = function (Project $p, Block $b, string $amount, User $sp, string $date, string $code): array {
        $plot = Plot::factory()->create(['project_id' => $p->id, 'block_id' => $b->id, 'status' => PlotStatus::Booked->value]);
        $booking = Booking::factory()->confirmed()->forPlot($plot)->create([
            'created_by' => $sp->id, 'booking_date' => $date,
            'final_amount' => $amount, 'base_amount' => $amount, 'subtotal' => $amount,
        ]);
        $buyer = Buyer::factory()->create(['status' => 'active', 'first_name' => 'Cust', 'last_name' => $code]);
        BookingBuyer::factory()->create(['booking_id' => $booking->id, 'buyer_id' => $buyer->id, 'is_primary' => true, 'ownership_percentage' => 100]);

        return ['booking' => $booking, 'buyer' => $buyer];
    };

    $A = $mk($alpha, $a1, '1000000', $asha, '2026-09-05', 'Aaa');
    $B = $mk($beta, $b1, '2000000', $ravi, '2026-09-08', 'Bbb');

    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $A['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '300000', 'payment_date' => '2026-09-12',
    ], $actor);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $actor);

    return compact('actor', 'asha', 'ravi', 'alpha', 'beta', 'a1', 'b1', 'A', 'B');
}

function misA(): MisAnalytics
{
    return app(MisAnalytics::class);
}

/**
 * @param  array<string, mixed>  $o
 */
function misFilter(array $o = []): ReportFilterData
{
    return new ReportFilterData(
        from: CarbonImmutable::parse($o['from'] ?? '2026-09-01')->startOfDay(),
        to: CarbonImmutable::parse($o['to'] ?? '2026-09-30')->endOfDay(),
        preset: DatePreset::Custom,
        projectId: $o['projectId'] ?? null,
        blockId: $o['blockId'] ?? null,
        salespersonId: $o['salespersonId'] ?? null,
    );
}

/*
| ---------------------------------------------------------------------------
| Customer portal helpers (M15)
| ---------------------------------------------------------------------------
*/

/**
 * Invite a buyer to the portal and return the raw activation token + buyer.
 *
 * @return array{token: string, buyer: Buyer}
 */
function inviteBuyer(array $attrs = []): array
{
    $buyer = Buyer::factory()->create(array_merge(
        ['status' => 'active', 'email' => fake()->unique()->safeEmail()],
        $attrs,
    ));

    $result = app(InviteCustomerToPortal::class)
        ->handle($buyer, User::factory()->create());

    return ['token' => str($result['link'])->afterLast('/')->value(), 'buyer' => $buyer->fresh()];
}

/** An active portal customer with a known password. */
function activePortalBuyer(string $password = 'Portal-pw-1234', array $attrs = []): Buyer
{
    ['token' => $token] = inviteBuyer($attrs);

    return app(ActivateCustomerPortal::class)
        ->handle($token, 'invite', $password)
        ->fresh();
}

/**
 * A confirmed ₹10L booking (₹300k paid directly) whose primary buyer is an
 * active portal customer. Shared by the portal bookings + payments tests.
 *
 * @return array{customer: Buyer, booking: Booking}
 */
function portalBooking(): array
{
    $s = confirmedBookingScenario('1000000');
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '300000', 'payment_date' => now()->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);

    $customer = $s['booking']->bookingBuyers()->where('is_primary', true)->first()->buyer;
    $customer->forceFill([
        'status' => 'active', 'email' => fake()->unique()->safeEmail(),
        'portal_status' => 'active', 'password' => bcrypt('pw'), 'portal_activated_at' => now(),
    ])->save();

    return ['customer' => $customer->fresh(), 'booking' => $s['booking']->fresh()];
}

/*
| ---------------------------------------------------------------------------
| Channel partners / commission helpers (M14)
| ---------------------------------------------------------------------------
*/

/**
 * A generated PENDING_REVIEW commission case worth ₹100,000 (2% of a ₹50L
 * booking), attributed 100% to one active, project-authorised partner. Shared
 * by the commission workflow / payout / reversal / access tests.
 *
 * @return array{actor: User, booking: Booking, case: CommissionCase, partner: Partner}
 */
function pendingCase(): array
{
    $s = confirmedBookingScenario('5000000');
    $actor = User::factory()->create();
    CommissionScheme::factory()->default()->published('2')->create();
    $partner = Partner::factory()->active()->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s['booking'], [
        ['partner_id' => $partner->id, 'share_percentage' => '100', 'role' => 'primary'],
    ], $actor);
    $case = app(GenerateCommissionCases::class)->handle($s['booking']->fresh(), $actor)->first();

    return ['actor' => $actor, 'booking' => $s['booking']->fresh(), 'case' => $case, 'partner' => $partner];
}

/*
| ---------------------------------------------------------------------------
| Communication engine helpers (M16)
| ---------------------------------------------------------------------------
*/

function commRequest(array $o = []): CommunicationRequest
{
    return new CommunicationRequest(
        channel: $o['channel'] ?? CommunicationChannel::Email,
        category: $o['category'] ?? CommunicationCategory::Transactional,
        to: $o['to'] ?? 'customer@example.com',
        body: $o['body'] ?? 'Your receipt RCPT-000001 is ready.',
        subject: $o['subject'] ?? 'Payment received',
        eventKey: $o['eventKey'] ?? 'payment.received',
        idempotencyKey: $o['idempotencyKey'] ?? null,
        context: $o['context'] ?? ['receipt_number' => 'RCPT-000001'],
    );
}
