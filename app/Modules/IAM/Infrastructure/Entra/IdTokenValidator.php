<?php

namespace App\Modules\IAM\Infrastructure\Entra;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class IdTokenValidator
{
    /** @return array<string, mixed> */
    public function validate(string $token, string $nonce): array
    {
        if (strlen($token) > 32768 || substr_count($token, '.') !== 2) {
            throw new RuntimeException('OIDC_INVALID_TOKEN');
        }
        $tenant = config('services.microsoft.tenant_id');
        $issuer = "https://login.microsoftonline.com/{$tenant}/v2.0";
        $cacheKey = 'oidc.jwks.'.$tenant;
        $metadata = Http::withoutRedirecting()->connectTimeout(3)->timeout(10)->get(
            "https://login.microsoftonline.com/{$tenant}/v2.0/.well-known/openid-configuration"
        )->throw()->json();
        $uri = $metadata['jwks_uri'] ?? '';
        $parts = is_string($uri) ? parse_url($uri) : false;
        if (($metadata['issuer'] ?? '') !== $issuer || ! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'login.microsoftonline.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException('OIDC_INVALID_METADATA');
        }
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true, 32, JSON_THROW_ON_ERROR);
        if (($header['alg'] ?? '') !== 'RS256' || ! is_string($header['kid'] ?? null)
            || isset($header['crit'])) {
            throw new RuntimeException('OIDC_INVALID_ALGORITHM');
        }
        $keys = Cache::remember($cacheKey, 3600, fn () => Http::withoutRedirecting()->connectTimeout(3)->timeout(10)->get($uri)->throw()->json());
        $keySet = JWK::parseKeySet($keys, 'RS256');
        if (! isset($keySet[$header['kid']])) {
            Cache::forget($cacheKey);
            $keys = Http::withoutRedirecting()->connectTimeout(3)->timeout(10)->get($uri)->throw()->json();
            Cache::put($cacheKey, $keys, 3600);
            $keySet = JWK::parseKeySet($keys, 'RS256');
        }
        $oldLeeway = JWT::$leeway;
        $oldTimestamp = JWT::$timestamp;
        try {
            JWT::$leeway = 60;
            JWT::$timestamp = now()->timestamp;
            $claims = (array) JWT::decode($token, $keySet);
        } catch (Throwable) {
            throw new RuntimeException('OIDC_INVALID_SIGNATURE_OR_TIME');
        } finally {
            JWT::$leeway = $oldLeeway;
            JWT::$timestamp = $oldTimestamp;
        }
        $clientId = config('services.microsoft.client_id');
        $aud = $claims['aud'] ?? null;
        $validAudience = $aud === $clientId
            || (is_array($aud) && in_array($clientId, $aud, true) && ($claims['azp'] ?? null) === $clientId);
        foreach (['exp', 'nbf', 'iat'] as $field) {
            if (! is_int($claims[$field] ?? null)) {
                throw new RuntimeException('OIDC_MISSING_TIME');
            }
        }
        if (! $validAudience || ($claims['iss'] ?? '') !== $issuer
            || ($claims['tid'] ?? '') !== $tenant
            || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])
            || ! is_string($claims['oid'] ?? null)
            || ! preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $claims['oid'])
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || $claims['exp'] <= now()->timestamp - 60
            || $claims['nbf'] > now()->timestamp + 60 || $claims['iat'] > now()->timestamp + 60
            || (isset($claims['azp']) && $claims['azp'] !== $clientId)) {
            throw new RuntimeException('OIDC_INVALID_CLAIMS');
        }

        return $claims;
    }
}
