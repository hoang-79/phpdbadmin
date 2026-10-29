{!! __('Guten Tag') !!}

{!! $intro !!}

{!! $code !!}

{!! __('Er ist :minutes Minuten gültig und nur einmal verwendbar.', ['minutes' => $minutes]) !!}

{!! __('Haben Sie keinen Code angefordert, können Sie diese Nachricht ignorieren.') !!}

@include('mail.text.footer')
