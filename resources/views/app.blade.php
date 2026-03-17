<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'CX Memories') }}</title>
        <link rel="icon" type="image/x-icon" href="/favicon.ico">
        <link rel="shortcut icon" href="/favicon.ico">
        <link rel="apple-touch-icon" href="/favicon.ico">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Blocking theme script: runs before any paint to prevent white flash -->
        <script>
            (function () {
                try {
                    var saved = localStorage.getItem('theme');
                    // Default to dark when no preference is stored
                    var theme = (saved === 'light') ? 'light' : 'dark';
                    var html = document.documentElement;
                    html.classList.remove('dark', 'light');
                    html.classList.add(theme);
                } catch (e) {
                    // localStorage unavailable (private browsing etc.) – fall back to dark
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
