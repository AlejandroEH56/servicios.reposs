<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PortalController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $path = public_path('portal/index.html');
        if (! is_file($path) && ! app()->isProduction()) {
            $path = base_path('frontend/dist/servicios-ui/browser/index.html');
        }
        abort_unless(is_file($path), 503);
        $nonce = Str::random(40);
        $html = file_get_contents($path);
        $html = str_replace('<app-root', '<app-root ngCspNonce="'.$nonce.'"', $html);
        $html = str_replace('<style', '<style nonce="'.$nonce.'"', $html);
        $html = str_replace('<script', '<script nonce="'.$nonce.'"', $html);
        $policy = "default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self'; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; trusted-types angular angular#bundler; require-trusted-types-for 'script'";

        return response($html)->withHeaders(['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => $policy,
            'Cache-Control' => 'no-store, max-age=0', 'Pragma' => 'no-cache']);
    }
}
