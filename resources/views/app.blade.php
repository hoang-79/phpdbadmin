<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #ffffff;
            }

            html.dark {
                background-color: #0b1120;
            }
        </style>

        {{-- Prüfsumme in der Adresse: Browser halten Favicons lange fest — so holen sie nach jeder Änderung das neue.
             Ist eine Verbindung der Umgebung Produktion offen, ist das Favicon rot (Gestaltung § 3); beim Wechsel in
             der App tauscht es useEnvironmentIcon über data-default und data-production. --}}
        @php($icon = fn (string $file): string => '/'.$file.'?v='.substr((string) md5_file(public_path($file)), 0, 8))
        @php($production = data_get($page, 'props.workspace.connection.environment') === 'production')
        <link rel="icon" href="{{ $icon($production ? 'favicon-produktion.ico' : 'favicon.ico') }}" sizes="any" data-default="{{ $icon('favicon.ico') }}" data-production="{{ $icon('favicon-produktion.ico') }}">
        <link rel="icon" href="{{ $icon($production ? 'favicon-produktion.svg' : 'favicon.svg') }}" type="image/svg+xml" data-default="{{ $icon('favicon.svg') }}" data-production="{{ $icon('favicon-produktion.svg') }}">
        <link rel="apple-touch-icon" href="{{ $icon('apple-touch-icon.png') }}">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'phpdbadmin') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
