<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Branded tab icon (POLISH.3) — the deep-eucalyptus BrandMark leaf as an inline SVG, no asset. --}}
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='6' fill='%2335462f'/%3E%3Cpath d='M6 18C6 11 9.5 6.2 18.5 5.8c-.4 8.6-4.7 12.9-12.5 12.2z' fill='%23cdd7c4'/%3E%3C/svg%3E">

        <title inertia>{{ config('app.name', 'CareOS') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead

        {{-- Branded pre-mount splash (POLISH.3) — a warm brand frame instead of a blank flash on first
             load; removed the instant Vue mounts (see app.ts). Tokens come from app.css (loaded blocking). --}}
        <style>
            #app-splash{position:fixed;inset:0;z-index:60;display:flex;align-items:center;justify-content:center;background:var(--color-surface-muted)}
            #app-splash .mark{width:44px;height:44px;border-radius:12px;background:var(--color-euca-800);animation:careos-splash 1.1s ease-in-out infinite}
            @keyframes careos-splash{0%,100%{opacity:.55;transform:scale(.94)}50%{opacity:1;transform:scale(1)}}
        </style>
    </head>
    <body class="h-full bg-surface-muted font-sans text-ink antialiased">
        <div id="app-splash" aria-hidden="true"><span class="mark"></span></div>
        @inertia
    </body>
</html>
