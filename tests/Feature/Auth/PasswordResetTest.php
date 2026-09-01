<?php

declare(strict_types=1);

use App\Livewire\Auth\ForgotPassword;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('sends a reset link without revealing whether the email exists', function () {
    Notification::fake();
    $user = User::factory()->create();

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('sendResetLink')
        ->assertHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);

    Livewire::test(ForgotPassword::class)
        ->set('email', 'nobody@example.com')
        ->call('sendResetLink')
        ->assertHasNoErrors();
});

it('resets the password with a valid token', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password')]);
    $token = Password::createToken($user);

    Livewire::test(App\Livewire\Auth\ResetPassword::class, ['token' => $token])
        ->set('email', $user->email)
        ->set('password', 'brand-new-password')
        ->set('password_confirmation', 'brand-new-password')
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue();
});

it('rejects an invalid reset token', function () {
    $user = User::factory()->create();

    Livewire::test(App\Livewire\Auth\ResetPassword::class, ['token' => 'not-a-real-token'])
        ->set('email', $user->email)
        ->set('password', 'brand-new-password')
        ->set('password_confirmation', 'brand-new-password')
        ->call('resetPassword')
        ->assertHasErrors('email');
});
