<div>
    <h1 class="text-lg font-semibold text-(--content)">Reset your password</h1>
    <p class="mt-1 text-sm text-(--content-muted)">Choose a new password for your account.</p>

    <form wire:submit="resetPassword" class="mt-6 space-y-4">
        <x-ui.input
            type="email"
            label="Email address"
            wire:model="email"
            autocomplete="username"
            required
            :error="$errors->first('email')" />

        <x-ui.input
            type="password"
            label="New password"
            wire:model="password"
            autocomplete="new-password"
            required
            :error="$errors->first('password')" />

        <x-ui.input
            type="password"
            label="Confirm new password"
            wire:model="password_confirmation"
            autocomplete="new-password"
            required />

        <x-ui.button type="submit" class="w-full">Reset password</x-ui.button>
    </form>
</div>
