<?php

declare(strict_types=1);

use App\Livewire\Leads\LeadForm;
use App\Models\Buyer;
use App\Models\Lead;
use App\Support\Leads\DuplicateFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('finds duplicate leads by phone (digits only) and email', function () {
    $existing = Lead::factory()->create(['phone' => '+91 98765 43210', 'email' => 'A@Example.com']);

    $finder = app(DuplicateFinder::class);

    expect($finder->leads('9876543210'))->toHaveCount(1)
        ->and($finder->leads('98765-43210')->first()->is($existing))->toBeTrue()
        ->and($finder->leads('0000000000', 'a@example.com'))->toHaveCount(1)
        ->and($finder->leads('0000000000', 'nobody@example.com'))->toHaveCount(0);
});

it('excludes the current lead from its own duplicate check', function () {
    $lead = Lead::factory()->create(['phone' => '9876543210']);

    expect(app(DuplicateFinder::class)->leads('9876543210', null, $lead->id))->toHaveCount(0);
});

it('surfaces duplicates on the lead form without blocking creation', function () {
    Lead::factory()->create(['name' => 'First Enquiry', 'phone' => '9876543210']);

    $component = Livewire::actingAs(leadManager())
        ->test(LeadForm::class)
        ->set('phone', '9876543210')
        ->assertSee('First Enquiry')
        ->assertSee('Possible duplicate');

    // Still allowed to create — records are never auto-merged.
    $component->set('name', 'Second Enquiry')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::where('phone', '9876543210')->count())->toBe(2);
});

it('finds existing buyers by phone / alternate phone / email', function () {
    $buyer = Buyer::factory()->create(['phone' => '9876543210', 'alternate_phone' => '9000000000', 'email' => 'b@example.com']);

    $finder = app(DuplicateFinder::class);

    expect($finder->buyers('9876543210')->first()->is($buyer))->toBeTrue()
        ->and($finder->buyers('9000000000')->first()->is($buyer))->toBeTrue()
        ->and($finder->buyers('0000000000', 'b@example.com')->first()->is($buyer))->toBeTrue();
});
