<?php

namespace Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sicherheits-Header (N-02, T-14): CSP mit Nonce, kein Einbetten, kein Referer nach draussen.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_carries_a_csp_whose_nonce_unlocks_only_its_own_inline_script(): void
    {
        $response = $this->get(route('login'));

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]+)'/", $policy);
        preg_match("/'nonce-([^']+)'/", $policy, $nonce);
        $this->assertStringContainsString('<script nonce="'.$nonce[1].'">', (string) $response->getContent());

        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_the_nonce_changes_with_every_response(): void
    {
        $first = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');
        $second = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }
}
