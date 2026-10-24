<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IdentifierBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_and_framework_exceptions_pass(): void
    {
        $this->artisan('modernization:verify-identifiers')->assertSuccessful();
    }

    public function test_residency_catalogs_are_converted_before_their_routes_are_enabled(): void
    {
        $this->artisan('modernization:verify-identifiers', ['--enable-module' => ['residencies']])->assertSuccessful();
        foreach (['modalidades', 'sectores', 'ramos'] as $catalog) {
            $column = collect(Schema::getColumns('residencias_'.$catalog))->firstWhere('name', 'id');
            $this->assertContains($column['type_name'], ['char', 'varchar']);
            $this->assertFalse($column['auto_increment']);
        }
    }

    public function test_unknown_numeric_domain_identity_is_rejected(): void
    {
        Schema::create('unexpected_domain', fn (Blueprint $table) => $table->id());
        try {
            $this->artisan('modernization:verify-identifiers')->assertFailed();
        } finally {
            Schema::drop('unexpected_domain');
        }
    }

    public function test_future_catalog_requires_conversion_before_module_or_route_is_enabled(): void
    {
        Schema::create('inventario_detalles_corte_mensual', fn (Blueprint $table) => $table->id());
        try {
            $this->artisan('modernization:verify-identifiers')->assertSuccessful();
            $this->artisan('modernization:verify-identifiers', ['--enable-module' => ['inventory']])->assertFailed();
            Route::post('/api/v1/inventory/details', fn () => response()->noContent());
            $this->artisan('modernization:verify-identifiers')->assertFailed();
        } finally {
            Schema::drop('inventario_detalles_corte_mensual');
        }
    }

    public function test_numeric_catalog_foreign_key_is_rejected_after_primary_key_conversion(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL rejects mismatched FK types at DDL creation; exercised with SQLite.');
        }
        Schema::create('inventario_detalles_corte_mensual', fn (Blueprint $table) => $table->char('id', 26)->primary());
        Schema::create('organization_fk_probe', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('level_id');
            $table->foreign('level_id')->references('id')->on('inventario_detalles_corte_mensual');
        });
        try {
            $this->artisan('modernization:verify-identifiers', ['--enable-module' => ['inventory']])->assertFailed();
        } finally {
            Schema::drop('organization_fk_probe');
            Schema::drop('inventario_detalles_corte_mensual');
        }
    }

    public function test_future_catalog_exception_only_permits_numeric_identity(): void
    {
        Schema::create('inventario_detalles_corte_mensual', fn (Blueprint $table) => $table->json('id'));
        try {
            $this->artisan('modernization:verify-identifiers')->assertFailed();
        } finally {
            Schema::drop('inventario_detalles_corte_mensual');
        }
    }

    public function test_missing_or_unknown_module_cannot_be_enabled(): void
    {
        $this->artisan('modernization:verify-identifiers', ['--enable-module' => ['inventory']])->assertFailed();
        $this->artisan('modernization:verify-identifiers', ['--enable-module' => ['unknown']])->assertFailed();
    }
}
