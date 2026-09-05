<div>
    <h1 class="text-lg font-semibold text-(--content)">Forgot your password?</h1>

    @if ($sent)
        <x-ui.alert variant="success" class="mt-4">
            If that email is registered for portal access, our team has been notified and will send you
            reset instructions.
        </x-ui.alert>
        <x-ui.button :href="route('portal.login')" wire:navigate class="mt-4 w-full">Back to sign in</x-ui.button>
    @else
        <p class="mt-1 text-sm text-(--content-muted)">
            Enter your email address and our team will help you reset your password.
        </p>

        <form wire:submit="request" class="mt-6 space-y-4">
            <x-ui.input type="email" label="Email address" wire:model="email" required autofocus
                :error="$errors->first('email')" />
            <x-ui.button type="submit" class="w-full">Request reset</x-ui.button>
            <a href="{{ route('portal.login') }}" wire:navigate class="block text-center text-sm text-(--brand-primary) hover:underline">Back to sign in</a>
        </form>
    @endif
</div>
