@props([
    'title' => null,
    'breadcrumbs' => [],
])

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
<body class="h-full antialiased text-(--content)"
      x-data="{ sidebarOpen: false }"
      @keydown.escape.window="sidebarOpen = false">

    <div class="min-h-full lg:grid lg:grid-cols-[16rem_1fr]">

        {{-- Sidebar --}}
        <x-app.sidebar />

        {{-- Main column --}}
        <div class="flex min-h-screen flex-col">
            <x-app.topbar :breadcrumbs="$breadcrumbs" />

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <div class="mx-auto w-full max-w-7xl space-y-6">
                    {{ $slot }}
                </div>
            </main>

            <footer class="border-t border-(--border) px-4 py-4 text-center text-xs text-(--content-muted) sm:px-6 lg:px-8">
                {{ $branding->name }} Business OS — M0 Foundation
            </footer>
        </div>
    </div>

    {{-- Global overlays --}}
    <x-ui.toast />

    @livewireScripts
    @stack('scripts')
</body>
</html>
