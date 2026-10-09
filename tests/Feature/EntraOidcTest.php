<?php

namespace Tests\Feature;

use App\Modules\IAM\Infrastructure\Entra\EntraOidcClient;
use App\Modules\IAM\Infrastructure\Entra\IdTokenValidator;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EntraOidcTest extends TestCase
{
    private string $key = '';

    private array $jwk;

    private string $tenant = '11111111-1111-4111-8111-111111111111';

    private string $client = '22222222-2222-4222-8222-222222222222';

    private string $object = '33333333-3333-4333-8333-333333333333';

    private string $group = '44444444-4444-4444-8444-444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['services.microsoft.tenant_id' => $this->tenant, 'services.microsoft.client_id' => $this->client,
            'services.microsoft.client_secret' => 'synthetic-secret', 'services.microsoft.redirect_uri' => 'https://app.example.test/auth/microsoft/callback',
            'services.microsoft.atlos_user_group_id' => $this->group]);
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $portableConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (is_file($portableConfig)) {
            $options['config'] = $portableConfig;
        }
        $resource = openssl_pkey_new($options);
        openssl_pkey_export($resource, $this->key, null, $options);
        $details = openssl_pkey_get_details($resource);
        $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $this->jwk = ['kty' => 'RSA', 'kid' => 'key1', 'alg' => 'RS256', 'use' => 'sig', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])];
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function token(array $overrides = []): string
    {
        return JWT::encode(array_replace(['iss' => "https://login.microsoftonline.com/{$this->tenant}/v2.0", 'aud' => $this->client,
            'tid' => $this->tenant, 'oid' => $this->object, 'sub' => 'synthetic-subject', 'nonce' => 'nonce',
            'iat' => now()->timestamp, 'nbf' => now()->timestamp, 'exp' => now()->timestamp + 3600], $overrides), $this->key, 'RS256', 'key1');
    }

    private function fakeProvider(?string $token = null, ?array $groups = null, ?string $object = null): void
    {
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response(['issuer' => "https://login.microsoftonline.com/{$this->tenant}/v2.0", 'jwks_uri' => 'https://login.microsoftonline.com/common/discovery/v2.0/keys']),
            '*/discovery/v2.0/keys' => Http::response(['keys' => [$this->jwk]]),
            '*/oauth2/v2.0/token' => Http::response(['id_token' => $token ?? $this->token(), 'access_token' => 'synthetic-access-token']),
            '*/me/checkMemberGroups' => Http::response(['value' => $groups ?? [$this->group]]),
            '*/me?*' => Http::response(['id' => $object ?? $this->object, 'displayName' => 'Test User', 'mail' => 'TEST@example.test', 'userPrincipalName' => 'test@example.test']),
        ]);
    }

    public function test_pkce_nonce_scopes_and_verified_graph_identity(): void
    {
        $client = app(EntraOidcClient::class);
        parse_str(parse_url($client->authorizationUrl('state', 'nonce', 'verifier'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', 'verifier', true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertSame('nonce', $query['nonce']);
        $this->assertSame('openid profile email User.Read', $query['scope']);
        $this->fakeProvider();
        $identity = $client->authenticate('code', 'verifier', 'nonce');
        $this->assertSame($this->object, $identity->objectId);
        $this->assertSame('test@example.test', $identity->email);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/token') && $request['code_verifier'] === 'verifier');
    }

    public static function invalidClaims(): array
    {
        return [['nonce', 'wrong'], ['aud', 'wrong'], ['iss', 'https://evil.example'], ['tid', 'wrong'], ['oid', 'not-a-uuid'], ['sub', ''], ['exp', 1], ['nbf', 2147483647], ['iat', 2147483647], ['azp', 'wrong']];
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_claims_are_rejected_before_graph(string $field, mixed $value): void
    {
        $this->fakeProvider($this->token([$field => $value]));
        try {
            app(EntraOidcClient::class)->authenticate('code', 'verifier', 'nonce');
            $this->fail('Invalid token accepted.');
        } catch (RuntimeException) {
            Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com'));
        }
    }

    public static function clockBoundaries(): array
    {
        return [['exp', -59, true], ['exp', -60, false], ['nbf', 60, true], ['nbf', 61, false], ['iat', 60, true], ['iat', 61, false]];
    }

    #[DataProvider('clockBoundaries')]
    public function test_clock_tolerance_is_bounded_to_sixty_seconds(string $claim, int $offset, bool $allowed): void
    {
        $this->fakeProvider($this->token([$claim => now()->timestamp + $offset]));
        if ($allowed) {
            $this->assertSame($this->object, app(EntraOidcClient::class)->authenticate('code', 'verifier', 'nonce')->objectId);
        } else {
            try {
                app(EntraOidcClient::class)->authenticate('code', 'verifier', 'nonce');
                $this->fail('Token outside clock tolerance accepted.');
            } catch (RuntimeException) {
                Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com'));
            }
        }
    }

    public function test_wrong_signature_is_rejected(): void
    {
        $token = $this->token();
        $parts = explode('.', $token);
        $parts[2] = str_repeat('A', strlen($parts[2]));
        $this->fakeProvider(implode('.', $parts));
        $this->expectException(RuntimeException::class);
        app(EntraOidcClient::class)->authenticate('code', 'verifier', 'nonce');
    }

    public function test_symmetric_algorithm_is_rejected(): void
    {
        $this->fakeProvider();
        $this->expectException(RuntimeException::class);
        app(IdTokenValidator::class)->validate(JWT::encode(['nonce' => 'nonce'], str_repeat('x', 64), 'HS256', 'key1'), 'nonce');
    }

    public function test_group_denial_is_rejected(): void
    {
        $this->fakeProvider(groups: []);
        try {
            app(EntraOidcClient::class)->authenticate('code', 'verifier', 'nonce');
            $this->fail('Denied group accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('IAM_GROUP_DENIED', $exception->getMessage());
        }
    }

    public function test_graph_object_mismatch_is_rejected(): void
    {
        $this->fakeProvider(object: $this->client);
        $this->expectExceptionMessage('IAM_GRAPH_ID_MISMATCH');
        app(EntraOidcClient::class)->authenticate('code', 'verifier', 'nonce');
    }

    public function test_jwks_rotation_refreshes_unknown_key_once(): void
    {
        $this->fakeProvider();
        Cache::put('oidc.jwks.'.$this->tenant, ['keys' => [array_replace($this->jwk, ['kid' => 'old'])]], 3600);
        $this->assertSame($this->object, app(IdTokenValidator::class)->validate($this->token(), 'nonce')['oid']);
        Http::assertSentCount(2);
    }

    public function test_metadata_cannot_redirect_jwks_to_other_host(): void
    {
        Http::fake(['*' => Http::response(['issuer' => "https://login.microsoftonline.com/{$this->tenant}/v2.0", 'jwks_uri' => 'https://evil.example/keys'])]);
        $this->expectExceptionMessage('OIDC_INVALID_METADATA');
        app(IdTokenValidator::class)->validate($this->token(), 'nonce');
    }

    public function test_http_callback_is_rejected_outside_loopback(): void
    {
        config(['services.microsoft.redirect_uri' => 'http://app.example.test/auth/microsoft/callback']);
        $this->expectExceptionMessage('OIDC_CONFIGURATION_INVALID');
        app(EntraOidcClient::class)->authorizationUrl('state', 'nonce', 'verifier');
    }
}
