<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['laboratorios_solicitudes', 'laboratorios_detalles_practica', 'laboratorios_detalles_varios', 'laboratorios_detalles_extraordinarios'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Populated laboratory request baseline requires reconciliation.');
            }
        }
        if (! Schema::hasTable($tables[0])) {
            Schema::create($tables[0], function (Blueprint $table): void {
                $this->identifier($table, 'id')->primary();
                foreach (['id_identidad_solicitante', 'id_laboratorio', 'id_semestre'] as $column) {
                    $this->identifier($table, $column);
                }
                $table->enum('tipo_solicitud', ['PRACTICA', 'VARIA', 'EXTRAORDINARIA']);
                $table->string('titulo', 255);
                $table->text('proposito')->nullable();
                $table->dateTime('inicio_en', 6);
                $table->dateTime('fin_en', 6);
                $table->unsignedSmallInteger('numero_asistentes')->nullable();
                $table->enum('estado', ['BORRADOR', 'PENDIENTE', 'AUTORIZADO', 'RECHAZADO', 'CANCELADO', 'COMPLETADO'])->default('BORRADOR');
                $table->unsignedInteger('version')->default(1);
                foreach (['enviado_en', 'creado_en', 'actualizado_en', 'cancelado_en'] as $column) {
                    $table->dateTime($column, 6)->nullable();
                }
                $table->index(['id_laboratorio', 'estado', 'inicio_en', 'fin_en'], 'idx_laboratorios_solicitud_calendar');
                $table->index('id_semestre', 'idx_laboratorios_solicitud_semestre');
            });
        }
        Schema::table($tables[0], function (Blueprint $table): void {
            $table->text('insumos')->nullable();
            $table->index(['id_identidad_solicitante', 'id'], 'idx_laboratorios_solicitud_owner_id');
        });

        foreach (array_slice($tables, 1) as $name) {
            if (Schema::hasTable($name)) {
                continue;
            }
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $this->identifier($table, 'id_solicitud')->primary();
                $table->foreign('id_solicitud')->references('id')->on('laboratorios_solicitudes')->restrictOnDelete();
                if ($name === 'laboratorios_detalles_practica') {
                    foreach (['id_carrera', 'id_asignatura', 'id_grupo'] as $column) {
                        $this->identifier($table, $column);
                    }
                    $table->string('nombre_practica', 255);
                    $table->string('numero_practica', 40)->nullable();
                } elseif ($name === 'laboratorios_detalles_varios') {
                    $table->string('codigo_tipo_uso', 60);
                    $table->text('descripcion');
                } else {
                    $table->text('justificacion');
                    $this->identifier($table, 'id_empleado_solicitante')->nullable();
                }
            });
        }

        Schema::create('laboratorios_controles', function (Blueprint $table): void {
            $table->string('codigo', 30)->primary();
        });
        DB::table('laboratorios_controles')->insert(['codigo' => 'WRITER']);

        Schema::create('laboratorios_claves_idempotencia', function (Blueprint $table): void {
            $table->char('clave', 64)->primary();
            $table->char('hash_solicitud', 64);
            $table->text('cuerpo_respuesta');
            $table->dateTime('expira_en', 6)->index();
        });
    }

    private function identifier(Blueprint $table, string $name): ColumnDefinition
    {
        return $table->char($name, 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
    }

    public function down(): void
    {
        throw new RuntimeException('Laboratory request capture requires roll-forward or verified recovery.');
    }
};
