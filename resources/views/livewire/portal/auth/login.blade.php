<div>
    <h1 class="text-lg font-semibold text-(--content)">Sign in to the customer portal</h1>
    <p class="mt-1 text-sm text-(--content-muted)">Use the email address on your booking.</p>

    @if (session('status'))
        <x-ui.alert variant="success" class="mt-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form wire:submit="login" class="mt-6 space-y-4">
        <x-ui.input type="email" label="Email address" wire:model="email" autocomplete="username" required autofocus
            :error="$errors->first('email')" />

        <x-ui.input type="password" label="Password" wire:model="password" autocomplete="current-password" required
            :error="$errors->first('password')" />

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-(--content-muted)">
                <input type="checkbox" wire:model="remember" class="rounded border-(--border)"> Remember me
            </label>
            <a href="{{ route('portal.password.request') }}" wire:navigate class="text-sm font-medium text-(--brand-primary) hover:underline">
                Forgot password?
            </a>
        </div>

        <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </x-ui.button>
    </form>
</div>
