<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'CBDB') }}</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
        }
    </style>
    @viteReactRefresh
    @vite('resources/js/inertia/app.tsx')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
