<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireCsrfToken;
use App\Models\User;
use App\Modules\IAM\Application\EntraAuthenticator;
use App\Modules\IAM\Application\VerifiedIdentity;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SprintOneSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_login_creates_a_single_server_side_flow(): void
    {
        $this->mock(EntraAuthenticator::class, function ($mock): void {
            $mock->shouldReceive('authorizationUrl')->once()->withArgs(function (string $state, string $nonce, string $verifier): bool {
                return strlen($state) === 64 && strlen($nonce) === 64 && strlen($verifier) >= 43;
            })->andReturn('https://login.microsoftonline.com/tenant/oauth2/v2.0/authorize');
        });
        $this->get('/auth/entra/login')->assertRedirect('https://login.microsoftonline.com/tenant/oauth2/v2.0/authorize');
        $flow = session('microsoft_oidc');
        $this->assertSame(['state', 'nonce', 'verifier', 'created_at'], array_keys($flow));
        $this->assertSessionHasNoProviderTokens();
    }

    public function test_callback_destroys_the_pre_login_database_session_and_old_cookie_cannot_access_me(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->mock(EntraAuthenticator::class, function ($mock): void {
            $mock->shouldReceive('authenticate')->once()->with('code', 'verifier', 'nonce')->andReturn(
                new VerifiedIdentity('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', 'Persona de ensayo', 'shadow@example.test', null)
            );
        });
        $this->withSession(['microsoft_oidc' => [
            'state' => 'state', 'nonce' => 'nonce', 'verifier' => 'verifier', 'created_at' => now()->timestamp,
        ], 'url.intended' => 'https://evil.example']);
        $oldId = session()->getId();
        session()->save();
        $this->assertDatabaseHas('sessions', ['id' => $oldId]);

        $this->withCookie(config('session.cookie'), $oldId)->get('/auth/entra/callback?state=state&code=code')->assertRedirect('/dashboard')
            ->assertSessionMissing('microsoft_oidc')->assertSessionMissing('url.intended');
        $this->assertNotSame($oldId, session()->getId());
        $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
        $this->assertAuthenticated();
        $this->assertSessionHasNoProviderTokens();
        $newId = session()->getId();
        $this->withCookie(config('session.cookie'), $newId)->get('/dashboard')->assertRedirect('/portal/session');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $oldId)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_forged_logout_is_rejected_even_with_same_origin_metadata(): void
    {
        $this->enforceCsrf();
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['_token' => 'session-token']);
        $this->postJson('/auth/entra/logout')->assertStatus(419);
        $this->withHeader('Sec-Fetch-Site', 'same-origin')->postJson('/auth/entra/logout', ['_token' => 'wrong-token'])->assertStatus(419);
        $this->assertAuthenticatedAs($user);
        $this->get('/auth/entra/logout')->assertMethodNotAllowed();
    }

    public function test_valid_logout_invalidates_session_rotates_csrf_and_returns_no_content_for_spa(): void
    {
        $this->enforceCsrf();
        $user = User::factory()->create();
        $this->actingAs($user)->withSession([
            '_token' => 'session-token', 'iam_authenticated_at' => now()->timestamp,
            'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => $user->version_autorizacion,
        ]);
        $oldId = session()->getId();
        $this->postJson('/auth/entra/logout', ['_token' => 'session-token'])->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private')->assertSessionMissing('iam_authenticated_at');
        $this->assertGuest();
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame('session-token', session()->token());
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_rejected_callback_leads_to_safe_access_denied_without_forwarding_provider_data(): void
    {
        $this->mock(EntraAuthenticator::class, fn ($mock) => $mock->shouldReceive('authenticate')->once()->andThrow(new RuntimeException('private-provider-detail')));
        $this->withSession(['microsoft_oidc' => [
            'state' => 'state', 'nonce' => 'nonce', 'verifier' => 'verifier', 'created_at' => now()->timestamp,
        ]])->get('/auth/entra/callback?state=state&code=private-code')->assertRedirect('/login');
        $this->get('/login')->assertRedirect('/portal/access-denied');
        $this->assertGuest();
        $this->assertDatabaseCount('iam_identidades', 0);
        $this->assertDatabaseCount('iam_cuentas_externas', 0);
    }

    public function test_csrf_bootstrap_is_available_without_authentication(): void
    {
        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $this->assertGuest();
        $this->get('/login')->assertRedirect('/portal/login');
        $this->assertSame(0, DB::table('iam_identidades')->count());
    }

    private function enforceCsrf(): void
    {
        $this->app->bind(RequireCsrfToken::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends RequireCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    private function assertSessionHasNoProviderTokens(): void
    {
        foreach (['access_token', 'id_token', 'refresh_token'] as $key) {
            $this->assertFalse(session()->has($key));
        }
    }
}
