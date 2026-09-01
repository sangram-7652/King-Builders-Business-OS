<div>
    <h1 class="text-lg font-semibold text-(--content)">Forgot your password?</h1>
    <p class="mt-1 text-sm text-(--content-muted)">
        Enter your email and we'll send you a link to reset it.
    </p>

    @if (session('status'))
        <x-ui.alert variant="success" class="mt-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form wire:submit="sendResetLink" class="mt-6 space-y-4">
        <x-ui.input
            type="email"
            label="Email address"
            wire:model="email"
            autocomplete="username"
            required
            autofocus
            :error="$errors->first('email')" />

        <x-ui.button type="submit" class="w-full">Email password reset link</x-ui.button>

        <p class="text-center text-sm text-(--content-muted)">
            <a href="{{ route('login') }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">
                Back to sign in
            </a>
        </p>
    </form>
</div>
