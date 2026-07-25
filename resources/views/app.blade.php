<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#020617"><meta name="application-name" content="IpamFerry"><link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/brand/favicon-32x32.png"><link rel="icon" type="image/png" sizes="16x16" href="/brand/favicon-16x16.png"><link rel="apple-touch-icon" sizes="180x180" href="/brand/apple-touch-icon.png"><title inertia>{{ config('app.name') }}</title>@viteReactRefresh @vite('resources/js/app.tsx') @inertiaHead</head>
<body class="bg-slate-950 text-slate-100">@inertia</body>
</html>
