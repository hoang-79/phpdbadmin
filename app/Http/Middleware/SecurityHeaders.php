<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sicherheits-Header (N-02, T-14): Content-Security-Policy mit Nonce für Skripte, kein Einbetten in fremde Seiten
 * (Clickjacking auf «Anwenden», «Prozess beenden»), keine Schema- und Tabellennamen im Referer nach draussen, kein
 * MIME-Raten. Im Entwicklungsmodus darf zusätzlich der Vite-Server Skripte, Stile und seinen WebSocket liefern;
 * in der Umgebung «local» meldet die Regel nur (Report-Only).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        // Lokal nur melden statt sperren: Laravel Herd fügt seinen Seiten ein eigenes Inline-Skript ohne Nonce hinzu
        // (Herd Studio). Verstösse der App selbst stehen trotzdem in der Konsole; überall sonst gilt die Regel.
        $header = app()->isLocal() ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $response->headers->set($header, $this->policy(Vite::cspNonce()));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    private function policy(?string $nonce): string
    {
        $hot = $this->viteDevServer();
        $script = trim("'self' 'nonce-{$nonce}' {$hot}");
        $connect = trim("'self' {$hot}".($hot !== '' ? ' '.preg_replace('#^http#', 'ws', $hot) : ''));

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            // Vue, CodeMirror und das Raster setzen Stile im Element — ohne 'unsafe-inline' bliebe die Oberfläche roh.
            "style-src 'self' 'unsafe-inline' {$hot}",
            "img-src 'self' data: blob:",
            "font-src 'self' data: {$hot}",
            "connect-src {$connect}",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /** Adresse des Vite-Servers, wenn `npm run dev` läuft (Datei public/hot) — sonst leer. */
    private function viteDevServer(): string
    {
        if (! Vite::isRunningHot()) {
            return '';
        }

        $url = trim((string) @file_get_contents(public_path('hot')));

        return preg_match('#^https?://[^\s;\'"]+$#', $url) === 1 ? rtrim($url, '/') : '';
    }
}
