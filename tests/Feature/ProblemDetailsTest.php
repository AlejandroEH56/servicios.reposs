<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ProblemDetailsTest extends TestCase
{
    public function test_api_not_found_is_problem_details_even_without_json_accept(): void
    {
        $id = '6f84a7d6-54ef-47cb-95ab-f0f53bfcab43';
        $this->withHeader('X-Correlation-ID', $id)->get('/api/v1/missing?secret=hidden')
            ->assertNotFound()->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('X-Correlation-ID', $id)->assertJsonPath('correlationId', $id)
            ->assertJsonPath('instance', '/api/v1/missing')->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_invalid_correlation_id_is_replaced(): void
    {
        $response = $this->withHeader('X-Correlation-ID', str_repeat('x', 512))->getJson('/health/live');
        $response->assertOk();
        $this->assertTrue(Str::isUuid($response->headers->get('X-Correlation-ID')));
    }

    public function test_internal_errors_hide_exception_details_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v1/test-error', function (): never {
            throw new RuntimeException('SQL password token private/path');
        });
        $response = $this->getJson('/api/v1/test-error');
        $response->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
    }

    public function test_validation_errors_do_not_echo_sensitive_values(): void
    {
        Route::get('/api/v1/test-validation', function (): never {
            throw ValidationException::withMessages(['secret' => 'value contains PII']);
        });
        $response = $this->getJson('/api/v1/test-validation');
        $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertStringNotContainsString('PII', $response->getContent());
    }

    public function test_rate_limit_and_method_not_allowed_preserve_required_headers(): void
    {
        Route::get('/api/v1/test-limit', fn () => abort(429, 'internal details', ['Retry-After' => '60']));
        $this->getJson('/api/v1/test-limit')->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->postJson('/health/live')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD');
    }
}
