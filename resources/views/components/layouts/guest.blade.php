@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title . ' · ' : '' }}{{ $branding->name }} Business OS</title>

    <x-app.branding-style />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-full items-center justify-center bg-(--surface-muted) px-4 py-12 antialiased text-(--content)">

    <div class="w-full max-w-md">
        <div class="mb-6 flex items-center justify-center gap-3">
            <span class="flex size-10 items-center justify-center rounded-xl bg-(--brand-primary) text-sm font-bold text-(--brand-primary-fg)">
                {{ $branding->initials() }}
            </span>
            <span class="text-lg font-semibold">{{ $branding->name }} Business OS</span>
        </div>

        <div class="rounded-2xl border border-(--border) bg-(--surface) p-6 shadow-sm sm:p-8">
            {{ $slot }}
        </div>

        <p class="mt-6 text-center text-xs text-(--content-muted)">
            &copy; {{ date('Y') }} {{ $branding->name }}. All rights reserved.
        </p>
    </div>

    <x-ui.toast />
    @livewireScripts
</body>
</html>
