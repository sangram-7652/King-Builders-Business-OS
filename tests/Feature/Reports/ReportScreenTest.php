<?php

declare(strict_types=1);

use App\Models\Block;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-08-15 09:00:00', 'UTC'));
});

function reportUser(array $extra = []): User
{
    return makeUser(permissions: array_merge(['reports.view'], $extra));
}

dataset('report routes', [
    'overview' => ['reports.overview', 'Executive dashboard'], // M11.2 replaced the placeholder
    'sales' => ['reports.sales', 'Sales report'],
    'inventory' => ['reports.inventory', 'Inventory report'],
    'mis' => ['reports.mis', 'MIS report'],
]);

/*
| RENDERING + AUTHORIZATION
*/

it('renders every report foundation page for a reports.view user', function (string $route, string $heading) {
    $this->actingAs(reportUser())
        ->get(route($route))
        ->assertOk()
        ->assertSee($heading)
        ->assertSee('Filters')
        ->assertSee('This month'); // default period label
})->with('report routes');

it('forbids a user without reports.view', function (string $route) {
    $this->actingAs(makeUser(permissions: ['bookings.view']))
        ->get(route($route))
        ->assertForbidden();
})->with('report routes');

it('redirects a guest to login', function () {
    $this->get(route('reports.sales'))->assertRedirect(route('login'));
});

it('shows the Reports nav only to a reports.view user', function () {
    $this->actingAs(reportUser())->get(route('dashboard'))->assertSee('Reports');
    $this->actingAs(makeUser(permissions: ['bookings.view']))->get(route('dashboard'))->assertDontSee('reports/sales');
});

/*
| URL PERSISTENCE + RESET
*/

it('reflects a fixed preset from the query string', function () {
    $this->actingAs(reportUser())
        ->get(route('reports.sales', ['preset' => 'last_month']))
        ->assertOk()
        ->assertSee('Last month')
        ->assertSee('01 Jul 2026');
});

it('reflects a custom range from the query string', function () {
    $this->actingAs(reportUser())
        ->get('/reports/sales?from=2026-06-01&to=2026-06-30')
        ->assertOk()
        ->assertSee('01 Jun 2026')
        ->assertSee('30 Jun 2026');
});

it('keeps the filters in tab links and offers a bare Reset link', function () {
    $project = Project::factory()->create();

    $html = $this->actingAs(reportUser(['projects.view']))
        ->get(route('reports.sales', ['preset' => 'today', 'project_id' => $project->id]))
        ->assertOk()
        ->getContent();

    // tab links carry the active filter query string forward
    expect($html)->toContain('reports/inventory?preset=today&amp;project_id='.$project->id)
        // the Reset control points at the bare report path
        ->toContain('href="'.route('reports.sales').'"');
});

it('a reset (bare URL) renders the default month with a clean query string', function () {
    $this->actingAs(reportUser())
        ->get(route('reports.sales'))
        ->assertOk()
        ->assertSee('This month')
        ->assertSee('01 Aug 2026')
        ->assertSee('31 Aug 2026');
});

/*
| TENANT ISOLATION / FOREIGN REJECTION (single-tenant → auth + referential)
*/

it('rejects a filter referencing a non-existent project', function () {
    $this->actingAs(reportUser(['projects.view']))
        ->getJson(route('reports.sales', ['project_id' => 999999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('project_id');
});

it('rejects a block that does not belong to the selected project', function () {
    $projectA = Project::factory()->create();
    $foreignBlock = Block::factory()->create(['project_id' => Project::factory()->create()->id]);

    $this->actingAs(reportUser(['projects.view']))
        ->getJson(route('reports.sales', ['project_id' => $projectA->id, 'block_id' => $foreignBlock->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('block_id');
});

it('rejects a non-existent salesperson', function () {
    $this->actingAs(reportUser())
        ->getJson(route('reports.sales', ['salesperson_id' => 999999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('salesperson_id');
});

it('rejects an inverted custom date range', function () {
    $this->actingAs(reportUser())
        ->getJson('/reports/sales?from=2026-08-31&to=2026-08-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');
});

/*
| FILTER OPTIONS ARE SCOPED
*/

it('offers only the active projects as filter options', function () {
    $active = Project::factory()->create(['name' => 'Green Meadows']);
    Project::factory()->inactive()->create(['name' => 'Archived Estate']);

    $this->actingAs(reportUser(['projects.view']))
        ->get(route('reports.sales'))
        ->assertOk()
        ->assertSee('Green Meadows')
        ->assertDontSee('Archived Estate');
});
