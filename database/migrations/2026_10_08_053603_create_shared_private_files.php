<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('compartido_archivos_almacenados')) {
            return;
        }
        Schema::create('compartido_archivos_almacenados', function (Blueprint $table) {
            $table->char('id', 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin')->primary();
            $table->string('disco', 40);
            $table->string('clave_objeto', 500);
            $table->string('nombre_original');
            $table->string('tipo_mime', 127);
            $table->unsignedBigInteger('tamano_bytes');
            $table->char('sha256', 64);
            $table->string('estado_escaneo', 20)->default('PENDIENTE');
            $table->char('id_identidad_carga', 26)->nullable();
            $table->dateTime('creado_en', 6);
            $table->dateTime('eliminado_en', 6)->nullable();
            $table->unique(['disco', 'clave_objeto'], 'uq_compartido_archivo_objeto');
            $table->index('sha256', 'idx_compartido_archivo_hash');
            $table->index(['id_identidad_carga', 'creado_en'], 'idx_compartido_archivo_cargador');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Private files use roll-forward; restore from a tested backup.');
    }
};
