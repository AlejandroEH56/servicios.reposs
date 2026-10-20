<?php

namespace Tests\Feature;

use Tests\TestCase;

class SprintThreeBrowserSecurityTest extends TestCase
{
    public function test_html_receives_unique_csp_nonce_and_trusted_types_enforcement(): void
    {
        $path = public_path('portal/index.html');
        $existed = is_file($path);
        $original = $existed ? file_get_contents($path) : null;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, '<html><style>body{color:black}</style><app-root></app-root></html>');
        try {
            $first = $this->get('/portal/notices')->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            $policy = $first->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("require-trusted-types-for 'script'", $policy);
            $this->assertStringNotContainsString('unsafe-inline', $policy);
            preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $match);
            $first->assertSee('ngCspNonce="'.$match[1].'"', false)->assertSee('<style nonce="'.$match[1].'"', false);
            $this->assertNotSame($policy, $this->get('/portal/notices')->assertOk()->headers->get('Content-Security-Policy'));
        } finally {
            if ($existed) {
                file_put_contents($path, $original);
            } else {
                unlink($path);
            }
        }
        $this->get('/avisos')->assertRedirect('/portal/notices');
        $this->get('/reposs/avisos')->assertRedirect('/portal/notices');
    }
}
