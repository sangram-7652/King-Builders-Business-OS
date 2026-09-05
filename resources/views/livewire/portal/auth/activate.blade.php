<div>
    @if (! $tokenValid)
        <h1 class="text-lg font-semibold text-(--content)">Link no longer valid</h1>
        <p class="mt-2 text-sm text-(--content-muted)">
            This activation link is invalid, has already been used, or has expired.
            Please contact us to request a new one.
        </p>
        <x-ui.button :href="route('portal.login')" wire:navigate class="mt-4 w-full">Back to sign in</x-ui.button>
    @else
        <h1 class="text-lg font-semibold text-(--content)">
            {{ $purpose === 'reset' ? 'Reset your password' : 'Set your password' }}
        </h1>
        <p class="mt-1 text-sm text-(--content-muted)">
            {{ $customerName ? 'Welcome, '.$customerName.'. ' : '' }}Choose a password to access your portal.
        </p>

        <form wire:submit="submit" class="mt-6 space-y-4">
            <x-ui.input type="password" label="New password" wire:model="password" autocomplete="new-password" required autofocus
                :error="$errors->first('password')" />
            <x-ui.input type="password" label="Confirm password" wire:model="password_confirmation" autocomplete="new-password" required />

            <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ $purpose === 'reset' ? 'Reset password' : 'Activate portal' }}</span>
                <span wire:loading wire:target="submit">Saving…</span>
            </x-ui.button>
        </form>
    @endif
</div>
