{{-- Einladung (A-06): ein Knopf zur Annahme, darunter der Link für Postfächer ohne Knöpfe. --}}
@extends('mail.html.layout')

@section('title', $subject)

@section('preheader', $intro)

@section('content')
    <h1 style="margin:0 0 12px 0; font-size:18px; line-height:26px; font-weight:600; color:#1c2534;">{{ __('Guten Tag') }}</h1>
    <p style="margin:0 0 24px 0;">{{ $intro }}</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
        <tr>
            <td style="background-color:#0b6e82; border-radius:6px;">
                <a href="{{ $url }}" style="display:inline-block; padding:12px 22px; font-size:15px; line-height:20px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:6px;">{{ __('Einladung annehmen') }}</a>
            </td>
        </tr>
    </table>
    <p style="margin:0 0 12px 0;">{{ $validity }}</p>
    <p style="margin:0 0 20px 0; color:#4a5568;">{{ __('Ein Passwort gibt es nicht: Sie melden sich mit einem Code per Mail oder mit einem Passkey an.') }}</p>
    <p style="margin:0; font-size:12px; line-height:18px; color:#5f6b7d;">
        {{ __('Falls der Knopf nicht funktioniert, öffnen Sie diesen Link:') }}<br>
        <a href="{{ $url }}" style="color:#0b6e82; word-break:break-all;">{{ $url }}</a>
    </p>
@endsection
