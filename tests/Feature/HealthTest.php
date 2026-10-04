<?php

namespace Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_liveness_is_independent_of_database_storage_and_worker(): void
    {
        DB::shouldReceive('select')->never();
        Storage::shouldReceive('disk')->never();
        Cache::shouldReceive('get')->never();
        $this->getJson('/health/live')->assertOk()->assertExactJson(['status' => 'live'])
            ->assertHeader('X-Correlation-ID');
    }

    public function test_readiness_recovers_after_worker_heartbeat_expires(): void
    {
        DB::shouldReceive('select')->with('SELECT 1')->times(3)->andReturn([]);
        Storage::fake('local');
        $key = config('modernization.health.outbox_heartbeat_key');
        Cache::put($key, now()->timestamp);
        $this->getJson('/health/ready')->assertOk()->assertExactJson(['status' => 'ready']);
        Cache::put($key, now()->subSeconds(121)->timestamp);
        $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        Cache::put($key, now()->timestamp);
        $this->getJson('/health/ready')->assertOk();
        $this->assertSame([], Storage::disk('local')->allFiles('.health'));
    }

    public function test_readiness_fails_without_worker_and_with_future_heartbeat(): void
    {
        DB::shouldReceive('select')->with('SELECT 1')->twice()->andReturn([]);
        Storage::fake('local');
        $this->getJson('/health/ready')->assertStatus(503);
        Cache::put(config('modernization.health.outbox_heartbeat_key'), now()->addMinutes(1)->timestamp);
        $this->getJson('/health/ready')->assertStatus(503);
    }

    public function test_database_failure_is_not_exposed(): void
    {
        DB::shouldReceive('select')->with('SELECT 1')->andThrow(new RuntimeException('sensitive-host password'));
        $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }

    public function test_private_storage_failure_makes_readiness_unavailable(): void
    {
        DB::shouldReceive('select')->with('SELECT 1')->andReturn([]);
        Storage::shouldReceive('disk')->andThrow(new RuntimeException('private/path'));
        $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }

    public function test_storage_probe_cleanup_failure_makes_readiness_unavailable(): void
    {
        DB::shouldReceive('select')->with('SELECT 1')->andReturn([]);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->andReturn($disk);
        Cache::put(config('modernization.health.outbox_heartbeat_key'), now()->timestamp);
        $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }
}
