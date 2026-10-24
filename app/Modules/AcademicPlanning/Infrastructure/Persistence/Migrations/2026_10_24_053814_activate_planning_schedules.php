<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('planificacion_horarios') && DB::table('planificacion_horarios')->exists()) {
            throw new RuntimeException('Populated schedule baseline requires explicit reconciliation.');
        }
        if (! Schema::hasTable('planificacion_horarios')) {
            Schema::create('planificacion_horarios', function (Blueprint $table): void {
                foreach (['id', 'id_semestre', 'id_laboratorio'] as $column) {
                    $table->char($column, 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
                }
                $table->primary('id');
                $table->foreign('id_semestre')->references('id')->on('planificacion_semestres')->restrictOnDelete();
                $table->foreign('id_laboratorio')->references('id')->on('planificacion_laboratorios')->restrictOnDelete();
                $table->unsignedTinyInteger('dia_semana');
                $table->time('inicio_en');
                $table->time('fin_en');
                $table->boolean('activo')->default(true);
                $table->dateTime('creado_en', 6)->nullable();
                $table->unique(['id_semestre', 'id_laboratorio', 'dia_semana', 'inicio_en', 'fin_en'], 'uq_planificacion_horario_franja');
            });
        }
        Schema::table('planificacion_horarios', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('actualizado_en', 6)->nullable();
            $table->index(['id_laboratorio', 'id_semestre', 'activo', 'dia_semana', 'inicio_en', 'fin_en'], 'idx_plan_schedule_lookup');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Schedules require roll-forward or restoration of the approved recovery set.');
    }
};
