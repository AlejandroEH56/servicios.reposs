<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function identifier(Blueprint $table, string $name = 'id'): void
    {
        $table->char($name, 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
    }

    public function up(): void
    {
        foreach (['publicaciones_avisos', 'publicaciones_recursos_aviso'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Populated Publications baseline requires explicit reconciliation; functional migration starts with new data.');
            }
        }
        if (! Schema::hasTable('publicaciones_avisos')) {
            Schema::create('publicaciones_avisos', function (Blueprint $table): void {
                $this->identifier($table);
                $table->primary('id');
                $table->string('tipo_aviso', 30);
                $table->string('titulo');
                $table->text('descripcion');
                $table->text('requisitos')->nullable();
                $table->string('url_externa', 2048)->nullable();
                $table->string('estado', 20)->default('BORRADOR');
                $table->char('id_identidad_publicador', 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin')->nullable();
                $table->dateTime('publicado_en', 6)->nullable();
                $table->date('fecha_cierre')->nullable();
                $table->dateTime('creado_en', 6);
                $table->dateTime('actualizado_en', 6);
                $table->dateTime('eliminado_en', 6)->nullable();
            });
        }
        Schema::table('publicaciones_avisos', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('vence_en', 6)->nullable();
            $table->index(['estado', 'publicado_en', 'vence_en', 'id'], 'idx_pub_vigencia');
        });
        if (! Schema::hasTable('publicaciones_recursos_aviso')) {
            Schema::create('publicaciones_recursos_aviso', function (Blueprint $table): void {
                $this->identifier($table, 'id_aviso');
                $this->identifier($table, 'id_archivo');
                $table->string('rol_recurso', 40)->default('IMAGEN');
                $table->unsignedSmallInteger('orden_visualizacion')->default(0);
                $table->primary(['id_aviso', 'id_archivo']);
                $table->foreign('id_aviso')->references('id')->on('publicaciones_avisos');
            });
        }
        Schema::table('publicaciones_recursos_aviso', function (Blueprint $table): void {
            $table->boolean('activo')->default(true);
            $table->unique('id_archivo', 'uq_pub_recurso_archivo');
        });
        Schema::create('publicaciones_imagenes_preparadas', function (Blueprint $table): void {
            $this->identifier($table);
            $table->primary('id');
            $this->identifier($table, 'id_identidad_carga');
            $table->unsignedInteger('ancho');
            $table->unsignedInteger('alto');
            $table->dateTime('expira_en', 6)->index();
            $table->dateTime('creado_en', 6);
            $table->foreign('id')->references('id')->on('compartido_archivos_almacenados');
        });
        Schema::create('publicaciones_claves_idempotencia', function (Blueprint $table): void {
            $this->identifier($table);
            $table->primary('id');
            $table->char('alcance', 64);
            $table->char('clave', 64);
            $table->char('hash_solicitud', 64);
            $table->longText('cuerpo_respuesta')->nullable();
            $table->dateTime('creado_en', 6);
            $table->dateTime('expira_en', 6)->index();
            $table->unique(['alcance', 'clave'], 'uq_pub_idempotencia');
        });
        Schema::create('publicaciones_cargas_idempotentes', function (Blueprint $table): void {
            $this->identifier($table);
            $table->primary('id');
            $table->char('clave', 64)->unique();
            $table->char('sha256', 64);
            $table->char('id_archivo', 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin')->nullable();
            $table->dateTime('creado_en', 6);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Publications uses roll-forward or verified portable recovery, never destructive down().');
    }
};
