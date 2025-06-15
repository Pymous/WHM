<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title inertia>{{ config('app.name', 'WHM') }}</title>
    <link rel="icon" href="{{ asset('img/logo.png') }}" type="image/png">


    @routes
    @vite(['resources/js/app.js', "resources/js/pages/{$page['component']}.vue"])
    @inertiaHead
</head>

<body class="font-sans antialiased bg-slate-900 text-white">
    @inertia
</body>

</html>
