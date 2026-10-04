<?php

namespace App\Modules\IAM\Infrastructure\Entra;

use App\Modules\IAM\Application\EntraAuthenticator;
use App\Modules\IAM\Application\VerifiedIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class EntraOidcClient implements EntraAuthenticator
{
    public function __construct(private IdTokenValidator $validator) {}

    public function authorizationUrl(string $state, string $nonce, string $verifier): string
    {
        $this->validateConfiguration();
        $tenant = config('services.microsoft.tenant_id');
        $query = http_build_query([
            'client_id' => config('services.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('services.microsoft.redirect_uri'),
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?{$query}";
    }

    public function authenticate(string $code, string $verifier, string $nonce): VerifiedIdentity
    {
        $this->validateConfiguration();
        $tenant = config('services.microsoft.tenant_id');
        $tokens = Http::asForm()->withoutRedirecting()->connectTimeout(3)->timeout(10)->post(
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'grant_type' => 'authorization_code',
                'client_id' => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'redirect_uri' => config('services.microsoft.redirect_uri'),
                'code' => $code, 'code_verifier' => $verifier,
                'scope' => 'openid profile email User.Read',
            ]
        )->throw()->json();
        if (! is_string($tokens['id_token'] ?? null) || ! is_string($tokens['access_token'] ?? null)) {
            throw new RuntimeException('OIDC_MISSING_TOKENS');
        }
        $claims = $this->validator->validate($tokens['id_token'], $nonce);
        $group = config('services.microsoft.atlos_user_group_id');
        $graph = Http::withToken($tokens['access_token'])->withoutRedirecting()->connectTimeout(3)->timeout(10);
        $member = $graph->post('https://graph.microsoft.com/v1.0/me/checkMemberGroups', ['groupIds' => [$group]])->throw()->json();
        if (! in_array($group, $member['value'] ?? [], true)) {
            throw new RuntimeException('IAM_GROUP_DENIED');
        }
        $profile = $graph->get('https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName')->throw()->json();
        if (strtolower((string) ($profile['id'] ?? '')) !== strtolower($claims['oid'])) {
            throw new RuntimeException('IAM_GRAPH_ID_MISMATCH');
        }
        $email = $profile['mail'] ?? $profile['userPrincipalName'] ?? null;
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254
            ? strtolower(trim($email)) : null;
        $name = $profile['displayName'] ?? 'Cuenta institucional';

        return new VerifiedIdentity(
            strtolower($tenant), strtolower($claims['oid']),
            mb_substr(is_string($name) ? $name : 'Cuenta institucional', 0, 255),
            $email, is_string($profile['userPrincipalName'] ?? null) ? mb_substr($profile['userPrincipalName'], 0, 254) : null,
        );
    }

    private function validateConfiguration(): void
    {
        foreach (['tenant_id', 'client_id', 'atlos_user_group_id'] as $key) {
            if (! Str::isUuid((string) config('services.microsoft.'.$key))) {
                throw new RuntimeException('OIDC_CONFIGURATION_MISSING');
            }
        }
        $callback = config('services.microsoft.redirect_uri');
        $parts = is_string($callback) ? parse_url($callback) : false;
        $localHttp = app()->environment('local', 'testing') && is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
        if (! filled(config('services.microsoft.client_secret')) || ! is_array($parts)
            || (! $localHttp && ($parts['scheme'] ?? '') !== 'https')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            throw new RuntimeException('OIDC_CONFIGURATION_INVALID');
        }
    }
}
