{{-- Code per Mail (A-02): ein Kästchen je Ziffer, darunter der ganze Code am Stück zum Markieren mit Doppelklick. --}}
@extends('mail.html.layout')

@section('title', $subject)

@section('preheader', __('Gültig :minutes Minuten, nur einmal verwendbar.', ['minutes' => $minutes]))

@section('content')
    <h1 style="margin:0 0 12px 0; font-size:18px; line-height:26px; font-weight:600; color:#1c2534;">{{ __('Guten Tag') }}</h1>
    <p style="margin:0 0 20px 0;">{{ $intro }}</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 0 -6px; border-collapse:separate; border-spacing:6px 0;">
        <tr>
            @foreach (str_split($code) as $digit)
                <td align="center" valign="middle" width="40" height="54" style="width:40px; height:54px; background-color:#e3f2f5; border:1px solid #b6dbe3; border-radius:8px; font-family:'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:26px; line-height:54px; font-weight:700; color:#0b6e82; text-align:center;">{{ $digit }}</td>
            @endforeach
        </tr>
    </table>
    <p style="margin:10px 0 20px 0; font-size:12px; line-height:18px; color:#5f6b7d;">
        {{ __('Am Stück zum Kopieren:') }}
        <span style="font-family:'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:13px; color:#1c2534; -webkit-user-select:all; user-select:all;">{{ $code }}</span>
    </p>
    <p style="margin:0 0 12px 0;">{{ __('Er ist :minutes Minuten gültig und nur einmal verwendbar.', ['minutes' => $minutes]) }}</p>
    <p style="margin:0; color:#4a5568;">{{ __('Haben Sie keinen Code angefordert, können Sie diese Nachricht ignorieren.') }}</p>
@endsection
