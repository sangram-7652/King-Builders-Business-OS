<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-06-15 09:00:00', 'UTC'));
});

it('renders the executive dashboard for a reports.view user', function () {
    $this->actingAs(makeUser(permissions: ['reports.view']))
        ->get(route('reports.overview'))
        ->assertOk()
        ->assertSee('Executive dashboard')
        ->assertSee('Total projects')
        ->assertSee('Booking value')
        ->assertSee('Attention required')
        ->assertSee('Recent bookings')
        ->assertSee('This month'); // default period
});

it('forbids a user without reports.view', function () {
    $this->actingAs(makeUser(permissions: ['bookings.view']))
        ->get(route('reports.overview'))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    $this->get(route('reports.overview'))->assertRedirect(route('login'));
});

it('persists filters and the chart metric in the URL', function () {
    $project = Project::factory()->create(['name' => 'Green Meadows']);

    $html = $this->actingAs(makeUser(permissions: ['reports.view', 'projects.view']))
        ->get(route('reports.overview', ['preset' => 'today', 'project_id' => $project->id, 'metric' => 'bookings']))
        ->assertOk()
        ->assertSee('Today')
        ->getContent();

    // the metric toggle keeps every active filter in its links
    expect($html)->toContain('metric=bookings')
        ->toContain('project_id='.$project->id)
        // tabs carry the filters onward too
        ->toContain('reports/sales?preset=today&amp;project_id='.$project->id);
});

it('rejects a foreign project filter (referential / tenant guard)', function () {
    $this->actingAs(makeUser(permissions: ['reports.view', 'projects.view']))
        ->getJson(route('reports.overview', ['project_id' => 999999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('project_id');
});

it('scopes the dashboard to a salesperson filter and forbids a scoped user picking another', function () {
    $me = makeUser(permissions: ['reports.view', 'leads.view']); // no leads.view_all
    $other = User::factory()->create();

    // a scoped user may not report on someone else
    $this->actingAs($me)
        ->getJson(route('reports.overview', ['salesperson_id' => $other->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('salesperson_id');

    // but the page renders for their own scope
    $this->actingAs($me)->get(route('reports.overview', ['salesperson_id' => $me->id]))->assertOk();
});

it('hides Top salespeople from a user without leads.view_all', function () {
    Booking::factory()->confirmed()->create([
        'created_by' => User::factory()->create(['name' => 'Hidden Star'])->id,
        'booking_date' => '2026-06-10', 'final_amount' => '5000000',
    ]);

    $this->actingAs(makeUser(permissions: ['reports.view', 'leads.view']))
        ->get(route('reports.overview'))
        ->assertOk()
        ->assertDontSee('Top salespeople')
        ->assertDontSee('Hidden Star');

    $this->actingAs(makeUser(permissions: ['reports.view', 'leads.view_all']))
        ->get(route('reports.overview'))
        ->assertOk()
        ->assertSee('Top salespeople');
});

it('shows the Reset control and a clean default state', function () {
    $this->actingAs(makeUser(permissions: ['reports.view']))
        ->get(route('reports.overview'))
        ->assertOk()
        ->assertSee('Reset')
        ->assertSee('01 Jun 2026')
        ->assertSee('30 Jun 2026');
});

it('renders an empty-but-honest dashboard when there is no data', function () {
    $this->actingAs(makeUser(permissions: ['reports.view']))
        ->get(route('reports.overview'))
        ->assertOk()
        ->assertDontSee('Unavailable')      // nothing failed
        ->assertSee('No confirmed bookings in this period.');
});
