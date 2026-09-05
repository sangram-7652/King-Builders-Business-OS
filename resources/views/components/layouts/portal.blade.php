@props(['title' => null])

@php
    $customer = auth('customer')->user();
    $nav = [
        ['label' => 'Dashboard', 'route' => 'portal.dashboard', 'icon' => 'home'],
        ['label' => 'My Bookings', 'route' => 'portal.bookings.index', 'icon' => 'inbox'],
        ['label' => 'Payments', 'route' => 'portal.payments.index', 'icon' => 'inbox'],
        ['label' => 'Documents', 'route' => 'portal.documents.index', 'icon' => 'building'],
        ['label' => 'Support', 'route' => 'portal.support.index', 'icon' => 'phone'],
        ['label' => 'My Profile', 'route' => 'portal.profile', 'icon' => 'user'],
    ];
    $nav = array_values(array_filter($nav, fn ($i) => \Illuminate\Support\Facades\Route::has($i['route'])));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ? $title . ' · ' : '' }}{{ $branding->name }} — Customer Portal</title>

    <x-app.branding-style />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-(--surface-muted) antialiased text-(--content)"
      x-data="{ menuOpen: false }">

    <header class="border-b border-(--border) bg-(--surface)">
        <div class="mx-auto flex h-16 max-w-5xl items-center justify-between gap-4 px-4 sm:px-6">
            <a href="{{ route('portal.dashboard') }}" wire:navigate class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-(--brand-primary) text-sm font-bold text-(--brand-primary-fg)">
                    {{ $branding->initials() }}
                </span>
                <span class="hidden text-sm font-semibold sm:block">{{ $branding->name }} <span class="font-normal text-(--content-muted)">Customer Portal</span></span>
            </a>

            <nav class="hidden items-center gap-1 lg:flex">
                @foreach ($nav as $item)
                    @php $active = request()->routeIs($item['route']); @endphp
                    <a href="{{ route($item['route']) }}" wire:navigate
                       @class([
                           'rounded-lg px-3 py-2 text-sm font-medium transition',
                           'bg-(--brand-primary) text-(--brand-primary-fg)' => $active,
                           'text-(--content-muted) hover:bg-(--surface-muted) hover:text-(--content)' => ! $active,
                       ])>{{ $item['label'] }}</a>
                @endforeach
            </nav>

            <div class="flex items-center gap-3">
                <span class="hidden text-sm text-(--content-muted) sm:block">{{ $customer?->fullName() }}</span>
                <form method="POST" action="{{ route('portal.logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-(--border) px-3 py-1.5 text-sm font-medium hover:bg-(--surface-muted)">Sign out</button>
                </form>
                <button type="button" @click="menuOpen = ! menuOpen" class="lg:hidden" aria-label="Menu">
                    <x-app.icon name="menu" class="size-6" />
                </button>
            </div>
        </div>

        <nav x-show="menuOpen" x-collapse class="border-t border-(--border) px-4 pb-3 lg:hidden" style="display:none">
            @foreach ($nav as $item)
                @php $active = request()->routeIs($item['route']); @endphp
                <a href="{{ route($item['route']) }}" wire:navigate
                   @class(['block rounded-lg px-3 py-2 text-sm font-medium', 'bg-(--brand-primary)/10 text-(--brand-primary)' => $active, 'text-(--content-muted)' => ! $active])>{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </header>

    <main class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6">
        <div class="space-y-6">
            {{ $slot }}
        </div>
    </main>

    <footer class="mx-auto max-w-5xl px-4 py-8 text-center text-xs text-(--content-muted) sm:px-6">
        &copy; {{ date('Y') }} {{ $branding->name }}. For assistance, raise a support request.
    </footer>

    <x-ui.toast />
    @livewireScripts
</body>
</html>
