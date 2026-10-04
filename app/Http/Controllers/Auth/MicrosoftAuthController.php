<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\IAM\Application\EntraAuthenticator;
use App\Modules\IAM\Application\IdentityProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MicrosoftAuthController extends Controller
{
    public function redirect(Request $request, EntraAuthenticator $entra): RedirectResponse
    {
        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        try {
            $url = $entra->authorizationUrl($state, $nonce, $verifier);
        } catch (Throwable) {
            return redirect()->route('login')->withErrors(['microsoft' => 'El acceso institucional no está disponible.']);
        }
        $request->session()->put('microsoft_oidc', [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'created_at' => now()->timestamp,
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request, EntraAuthenticator $entra, IdentityProvisioner $provisioner): RedirectResponse
    {
        $flow = $request->session()->pull('microsoft_oidc');
        $state = $request->input('state');
        abort_unless(is_array($flow) && is_string($flow['state'] ?? null) && is_string($flow['nonce'] ?? null)
            && is_string($flow['verifier'] ?? null) && is_int($flow['created_at'] ?? null)
            && is_string($state) && hash_equals($flow['state'], $state)
            && $flow['created_at'] <= now()->timestamp && $flow['created_at'] >= now()->timestamp - 600, 419);
        if ($request->filled('error')) {
            return redirect()->route('login')->withErrors(['microsoft' => 'No fue posible autenticar con Microsoft.']);
        }
        $code = $request->input('code');
        abort_unless(is_string($code) && $code !== '' && strlen($code) <= 4096, 400);
        try {
            $identity = $entra->authenticate($code, $flow['verifier'], $flow['nonce']);
            $id = $provisioner->provision($identity, $request->attributes->get('correlationId'));
            if (! Auth::loginUsingId($id)) {
                throw new \RuntimeException('IAM_SESSION_DENIED');
            }
            $request->session()->regenerate();
            $request->session()->put('iam_authenticated_at', now()->timestamp);
            $request->session()->put('iam_last_activity', now()->timestamp);
            $request->session()->forget('url.intended');

            return redirect()->route('dashboard');
        } catch (Throwable) {
            Log::warning('IAM login rejected', ['correlationId' => $request->attributes->get('correlationId'), 'code' => 'IAM_LOGIN_REJECTED']);

            return redirect()->route('login')->withErrors(['microsoft' => 'No fue posible autorizar tu acceso institucional.']);
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
