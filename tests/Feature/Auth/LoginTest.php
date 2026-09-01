<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('logs in with valid credentials', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->id)
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('fails with invalid credentials', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('blocks an inactive user from logging in', function () {
    $user = User::factory()->inactive()->create(['password' => Hash::make('secret-password')]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('logs out and invalidates the session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('throttles login after 5 failed attempts', function () {
    RateLimiter::clear('');
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    $component = Livewire::test(Login::class)->set('email', $user->email);

    foreach (range(1, 5) as $attempt) {
        $component->set('password', 'wrong')->call('login')->assertHasErrors('email');
    }

    $component->set('password', 'secret-password')->call('login')
        ->assertHasErrors('email');

    // Even the correct password is refused while throttled.
    expect(auth()->check())->toBeFalse()
        ->and($component->errors()->first('email'))->toContain('seconds');
});

it('deactivating a logged-in user ends their session on the next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->update(['status' => UserStatus::Inactive]);

    $this->get('/dashboard')->assertRedirect(route('login'));
    expect(auth()->check())->toBeFalse();
});
