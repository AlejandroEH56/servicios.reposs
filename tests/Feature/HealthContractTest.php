<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class HealthContractTest extends TestCase
{
    public function test_management_contract_contains_exactly_deployed_health_paths(): void
    {
        $spec = Yaml::parseFile(base_path('openapi/management.yaml'));
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'health/') && in_array('GET', $route->methods(), true)) {
                $actual[] = '/'.$route->uri();
            }
        }
        sort($actual);
        $expected = array_keys($spec['paths']);
        sort($expected);
        $this->assertSame($expected, $actual);
        $this->assertSame('/', $spec['servers'][0]['url']);
    }

    public function test_live_response_matches_required_properties_and_constant_in_contract(): void
    {
        $spec = Yaml::parseFile(base_path('openapi/management.yaml'));
        $schema = $spec['components']['schemas']['Live'];
        $response = $this->getJson('/health/live')->assertOk();
        $this->assertSame($schema['required'], array_keys($response->json()));
        $this->assertSame($schema['properties']['status']['const'], $response->json('status'));
        $this->assertNotNull($response->headers->get('X-Correlation-ID'));
    }
}
