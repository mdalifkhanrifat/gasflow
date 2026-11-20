<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-preference="system">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <script>
            (() => {
                const root = document.documentElement;
                let preference = 'system';

                try {
                    const saved = localStorage.getItem('gasflow-theme');
                    if (saved === 'light' || saved === 'dark') {
                        preference = saved;
                    }
                } catch {
                    // Use the system preference if storage is unavailable.
                }

                root.dataset.themePreference = preference;
                root.classList.toggle('dark', preference === 'dark' ||
                    (preference === 'system' && matchMedia('(prefers-color-scheme: dark)').matches));
            })();
        </script>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'GasFlow') }}</title>
        </x-inertia::head>
    </head>
    <body class="bg-background font-sans text-ink antialiased">
        <x-inertia::app />
    </body>
</html>
