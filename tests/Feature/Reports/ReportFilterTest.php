<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\DatePreset;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Models\Block;
use App\Models\Masters\LeadSource;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\ReportFilterResolver;
use App\Support\Reports\ReportFilterData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-08-15 10:30:00', 'UTC'));
    $this->resolver = app(ReportFilterResolver::class);
});

function reportManager(): User
{
    return makeUser(permissions: ['reports.view', 'reports.export', 'leads.view_all', 'projects.view', 'bookings.view']);
}

/*
| DATE PRESETS + DEFAULTS
*/

it('defaults to the current month with no other constraints', function () {
    $f = $this->resolver->resolve([], reportManager());

    expect($f)->toBeInstanceOf(ReportFilterData::class)
        ->and($f->preset)->toBe(DatePreset::ThisMonth)
        ->and($f->from->toDateString())->toBe('2026-08-01')
        ->and($f->to->toDateString())->toBe('2026-08-31')
        ->and($f->from->format('H:i:s'))->toBe('00:00:00')
        ->and($f->to->format('H:i:s'))->toBe('23:59:59')
        ->and($f->isDefault())->toBeTrue()
        ->and($f->toQueryString())->toBe([]);
});

it('resolves every fixed preset to a day-aligned window', function (string $preset, string $from, string $to) {
    $f = $this->resolver->resolve(['preset' => $preset], reportManager());

    expect($f->preset->value)->toBe($preset)
        ->and($f->from->toDateString())->toBe($from)
        ->and($f->to->toDateString())->toBe($to)
        ->and($f->from->format('H:i:s'))->toBe('00:00:00')
        ->and($f->to->format('H:i:s'))->toBe('23:59:59');
})->with([
    'today' => ['today', '2026-08-15', '2026-08-15'],
    'yesterday' => ['yesterday', '2026-08-14', '2026-08-14'],
    'this week' => ['this_week', '2026-08-10', '2026-08-16'],   // Mon–Sun
    'this month' => ['this_month', '2026-08-01', '2026-08-31'],
    'last month' => ['last_month', '2026-07-01', '2026-07-31'],
    'this quarter' => ['this_quarter', '2026-07-01', '2026-09-30'],
    'this year' => ['this_year', '2026-01-01', '2026-12-31'],
]);

it('emits the preset (not from/to) in the query string for a fixed preset', function () {
    $f = $this->resolver->resolve(['preset' => 'today'], reportManager());

    expect($f->toQueryString())->toBe(['preset' => 'today']);
});

/*
| CUSTOM RANGE
*/

it('accepts an explicit custom range', function () {
    $f = $this->resolver->resolve(['from' => '2026-06-01', 'to' => '2026-06-15'], reportManager());

    expect($f->preset)->toBe(DatePreset::Custom)
        ->and($f->from->toDateString())->toBe('2026-06-01')
        ->and($f->to->toDateString())->toBe('2026-06-15')
        ->and($f->to->format('H:i:s'))->toBe('23:59:59')
        ->and($f->toQueryString())->toBe(['from' => '2026-06-01', 'to' => '2026-06-15']);
});

it('treats a bare from/to (no preset param) as a custom range', function () {
    $f = $this->resolver->resolve(['from' => '2026-05-10'], reportManager());

    expect($f->preset)->toBe(DatePreset::Custom)
        ->and($f->from->toDateString())->toBe('2026-05-10');
});

it('rejects an inverted custom range', function () {
    expect(fn () => $this->resolver->resolve(['from' => '2026-08-31', 'to' => '2026-08-01'], reportManager()))
        ->toThrow(ValidationException::class);
});

it('rejects an unparseable date', function () {
    expect(fn () => $this->resolver->resolve(['from' => 'not-a-date'], reportManager()))
        ->toThrow(ValidationException::class);
});

it('rejects an unknown preset', function () {
    expect(fn () => $this->resolver->resolve(['preset' => 'all_time'], reportManager()))
        ->toThrow(ValidationException::class);
});

/*
| PROJECT / BLOCK
*/

it('accepts a real project id and round-trips it', function () {
    $project = Project::factory()->create();

    $f = $this->resolver->resolve(['project_id' => (string) $project->id], reportManager());

    expect($f->projectId)->toBe($project->id)
        ->and($f->toQueryString())->toBe(['project_id' => (string) $project->id]);
});

it('rejects a non-existent project id', function () {
    expect(fn () => $this->resolver->resolve(['project_id' => '999999'], reportManager()))
        ->toThrow(ValidationException::class);
});

it('accepts a block that belongs to the given project', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);

    $f = $this->resolver->resolve([
        'project_id' => (string) $project->id,
        'block_id' => (string) $block->id,
    ], reportManager());

    expect($f->blockId)->toBe($block->id);
});

it('rejects a block that belongs to a different project', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $foreignBlock = Block::factory()->create(['project_id' => $projectB->id]);

    expect(fn () => $this->resolver->resolve([
        'project_id' => (string) $projectA->id,
        'block_id' => (string) $foreignBlock->id,
    ], reportManager()))->toThrow(ValidationException::class);
});

/*
| SALESPERSON (RBAC-scoped)
*/

it('lets a leads.view_all user filter by any salesperson', function () {
    $other = User::factory()->create();

    $f = $this->resolver->resolve(['salesperson_id' => (string) $other->id], reportManager());

    expect($f->salespersonId)->toBe($other->id);
});

it('rejects a non-existent salesperson id', function () {
    expect(fn () => $this->resolver->resolve(['salesperson_id' => '999999'], reportManager()))
        ->toThrow(ValidationException::class);
});

it('forbids a scoped user from filtering by another salesperson', function () {
    $scoped = makeUser(permissions: ['reports.view', 'leads.view']); // NOT leads.view_all
    $other = User::factory()->create();

    expect(fn () => $this->resolver->resolve(['salesperson_id' => (string) $other->id], $scoped))
        ->toThrow(ValidationException::class);

    // ...but may filter by themselves
    $f = $this->resolver->resolve(['salesperson_id' => (string) $scoped->id], $scoped);
    expect($f->salespersonId)->toBe($scoped->id);
});

/*
| ENUM FILTERS + COMBINED + RESET
*/

it('parses the status + lead-source filters', function () {
    $source = LeadSource::factory()->create();

    $f = $this->resolver->resolve([
        'booking_status' => 'confirmed',
        'payment_status' => 'success',
        'plot_status' => 'booked',
        'lead_source' => (string) $source->id,
    ], reportManager());

    expect($f->bookingStatus)->toBe(BookingStatus::Confirmed)
        ->and($f->paymentStatus)->toBe(PaymentStatus::Success)
        ->and($f->plotStatus)->toBe(PlotStatus::Booked)
        ->and($f->leadSourceId)->toBe($source->id);
});

it('rejects an invalid status enum value', function () {
    expect(fn () => $this->resolver->resolve(['booking_status' => 'archived'], reportManager()))
        ->toThrow(ValidationException::class);
});

it('round-trips a fully combined filter set through the query string', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $source = LeadSource::factory()->create();
    $me = reportManager();

    $input = [
        'from' => '2026-07-01', 'to' => '2026-07-31',
        'project_id' => (string) $project->id,
        'block_id' => (string) $block->id,
        'salesperson_id' => (string) $me->id,
        'booking_status' => 'confirmed',
        'payment_status' => 'pending',
        'plot_status' => 'sold',
        'lead_source' => (string) $source->id,
    ];

    $f = $this->resolver->resolve($input, $me);
    $qs = $f->toQueryString();

    expect($qs)->toMatchArray($input);

    // resolving the round-tripped query string yields the same object
    $again = $this->resolver->resolve($qs, $me);
    expect($again->toQueryString())->toBe($qs)
        ->and($again->isDefault())->toBeFalse();
});

it('a reset (empty input) restores the default filter', function () {
    $f = $this->resolver->resolve([], reportManager());

    expect($f->isDefault())->toBeTrue()
        ->and($f->toQueryString())->toBe([])
        ->and($f->preset)->toBe(DatePreset::DEFAULT);
});
