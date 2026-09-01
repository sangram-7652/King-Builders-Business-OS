<?php

declare(strict_types=1);

use App\Masters\MasterRegistry;
use Database\Seeders\Masters\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->seed(MasterDataSeeder::class);
});

it('renders the master data overview with every group', function () {
    $this->actingAs(masterAdmin())
        ->get(route('masters.dashboard'))
        ->assertOk()
        ->assertSee('Master Data')
        ->assertSee('Property')
        ->assertSee('Finance')
        ->assertSee('Location')
        ->assertSee('Documents')
        ->assertSee('Operations');
});

it('renders the list and create form for every one of the 18 masters', function () {
    $this->actingAs(masterAdmin());

    MasterRegistry::all()->each(function ($resource): void {
        $this->get(route('masters.index', ['resource' => $resource->slug()]))
            ->assertOk()
            ->assertSee($resource->pluralLabel());

        $this->get(route('masters.create', ['resource' => $resource->slug()]))
            ->assertOk()
            ->assertSee('New '.strtolower($resource->singularLabel()));
    });
});

it('renders the edit form for a seeded record of every master', function () {
    $this->actingAs(masterAdmin());

    MasterRegistry::all()->each(function ($resource): void {
        $record = $resource->model()::query()->first();

        if ($record === null) {
            return; // masters with no seed rows (none, but be safe)
        }

        $this->get(route('masters.edit', ['resource' => $resource->slug(), 'record' => $record->getKey()]))
            ->assertOk()
            ->assertSee('Edit '.strtolower($resource->singularLabel()));
    });
});

it('shows Master Data in the sidebar for a permitted user and hides it otherwise', function () {
    $this->actingAs(masterAdmin())->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Master Data');

    $this->actingAs(makeUser())->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Master Data');
});
