<?php

declare(strict_types=1);

use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\RevokePartnerProjectAuthorizationAction;
use App\Enums\PartnerActivityType;
use App\Enums\PartnerStatus;
use App\Exceptions\DomainException;
use App\Models\Partner;
use App\Models\PartnerProjectAuthorization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('authorises a partner for a project and records the activity', function () {
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->create();
    $project = Project::factory()->create();

    $auth = app(AuthorizePartnerForProjectAction::class)->handle($partner, $project, $actor);

    expect($auth->status)->toBe('active')
        ->and($auth->authorized_by)->toBe($actor->id)
        ->and($auth->authorized_at)->not->toBeNull()
        ->and($partner->fresh()->authorizedProjects()->pluck('projects.id')->all())->toBe([$project->id])
        ->and($partner->fresh()->activities()->where('type', PartnerActivityType::ProjectAuthorized->value)->exists())->toBeTrue();
});

it('is idempotent — re-authorising an active pair changes nothing', function () {
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->create();
    $project = Project::factory()->create();

    $a = app(AuthorizePartnerForProjectAction::class)->handle($partner, $project, $actor);
    $b = app(AuthorizePartnerForProjectAction::class)->handle($partner->fresh(), $project, $actor);

    expect($b->id)->toBe($a->id)
        ->and(PartnerProjectAuthorization::count())->toBe(1)
        ->and($partner->fresh()->activities()->where('type', PartnerActivityType::ProjectAuthorized->value)->count())->toBe(1);
});

it('revokes an authorisation by flipping the row, never deleting it', function () {
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->create();
    $project = Project::factory()->create();

    $auth = app(AuthorizePartnerForProjectAction::class)->handle($partner, $project, $actor);
    $revoked = app(RevokePartnerProjectAuthorizationAction::class)->handle($auth, $actor, 'compliance');

    expect($revoked->id)->toBe($auth->id)
        ->and($revoked->status)->toBe('revoked')
        ->and($revoked->revoke_reason)->toBe('compliance')
        ->and(PartnerProjectAuthorization::count())->toBe(1)
        ->and($partner->fresh()->authorizedProjects()->count())->toBe(0)
        ->and($partner->fresh()->activities()->where('type', PartnerActivityType::ProjectRevoked->value)->exists())->toBeTrue();
});

it('re-activates the same row when a revoked project is authorised again', function () {
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->create();
    $project = Project::factory()->create();

    $auth = app(AuthorizePartnerForProjectAction::class)->handle($partner, $project, $actor);
    app(RevokePartnerProjectAuthorizationAction::class)->handle($auth, $actor);
    $again = app(AuthorizePartnerForProjectAction::class)->handle($partner->fresh(), $project, $actor);

    expect($again->id)->toBe($auth->id)
        ->and($again->status)->toBe('active')
        ->and($again->revoked_at)->toBeNull()
        ->and(PartnerProjectAuthorization::count())->toBe(1);
});

it('refuses to authorise a blacklisted or inactive partner', function () {
    $project = Project::factory()->create();

    foreach ([PartnerStatus::Blacklisted, PartnerStatus::Inactive] as $status) {
        $partner = Partner::factory()->status($status)->create();
        expect(fn () => app(AuthorizePartnerForProjectAction::class)->handle($partner, $project, User::factory()->create()))
            ->toThrow(DomainException::class);
    }
});

it('is idempotent on a double revoke', function () {
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->create();
    $auth = app(AuthorizePartnerForProjectAction::class)->handle($partner, Project::factory()->create(), $actor);

    app(RevokePartnerProjectAuthorizationAction::class)->handle($auth, $actor);
    app(RevokePartnerProjectAuthorizationAction::class)->handle($auth->fresh(), $actor);

    expect($partner->fresh()->activities()->where('type', PartnerActivityType::ProjectRevoked->value)->count())->toBe(1);
});
