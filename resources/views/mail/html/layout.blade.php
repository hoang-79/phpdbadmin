{{--
    Mail-Layout von phpdbadmin (Logo-Kit G-06): Logo oben, weisse Karte, Fusszeile. Stile stehen inline, weil viele
    Postfächer <style> verwerfen. Das Logo ist eingebettet (Content-ID), nicht verlinkt — Postfächer blockieren
    externe Bilder; data:-Bilder zeigen Gmail und Outlook im Web nicht.
--}}
@php
    $logo = isset($message) ? $message->embed(resource_path('brand/mail/logo@2x.png')) : '';
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: (string) config('app.url');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light">
<meta name="x-apple-disable-message-reformatting">
<title>@yield('title')</title>
</head>
<body style="margin:0; padding:0; background-color:#f6f7f9; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
{{-- Vorschauzeile im Posteingang. Sie erscheint auch auf gesperrten Bildschirmen — deshalb nie mit Code. --}}
<div style="display:none; max-height:0; max-width:0; overflow:hidden; opacity:0; mso-hide:all; font-size:1px; line-height:1px; color:#f6f7f9;">@yield('preheader')&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f6f7f9;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
                <tr>
                    <td style="padding:0 4px 20px 4px;">
                        <a href="{{ config('app.url') }}" style="text-decoration:none;">
                            <img src="{{ $logo }}" width="163" height="32" alt="phpdbadmin" style="display:block; border:0; outline:none; width:163px; height:32px;">
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="background-color:#ffffff; border:1px solid #dde2e8; border-radius:10px; padding:32px 28px; font-family:{{ $sans }}; font-size:15px; line-height:24px; color:#1c2534;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 4px 0 4px; font-family:{{ $sans }}; font-size:12px; line-height:18px; color:#5f6b7d;">
                        @yield('footer')
                        {{ __('Diese Nachricht kommt automatisch von phpdbadmin auf :host.', ['host' => $host]) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
