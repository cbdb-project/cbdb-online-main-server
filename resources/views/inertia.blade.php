<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        try {
            if (window.localStorage.getItem('fontMode') === 'serif') {
                document.documentElement.classList.add('font-serif-mode');
            }
        } catch (error) {
            // localStorage 不可用時保留預設 sans 模式。
        }
    </script>
    {{-- Google Fonts: Source Sans Pro + Noto Sans TC（與主站 dashboard-v3 對齊；CJK 不進 Vite bundle） --}}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@300;400;700&display=fallback">
    {{-- CJK serif 備援：serif 模式與人名、地名等歷史文本使用 Source Serif 4 / Source Han Serif TC / Jigmo TC。 --}}
    <link rel="stylesheet" href="https://jigmo.digitalhumanities.dev/jigmo-tc.css">
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
    {{-- CHGIS 地圖前端資源：使 .chgis-place-link 點擊在 React 頁亦能浮出以 chgis_map.mbtiles
         為底圖的無邊框地圖（取代 /app/maps iframe）。partial 以 @push('scripts') 注入 config + @vite。 --}}
    @include('biogmains._chgis_map_assets')
    @stack('scripts')
</body>
</html>
