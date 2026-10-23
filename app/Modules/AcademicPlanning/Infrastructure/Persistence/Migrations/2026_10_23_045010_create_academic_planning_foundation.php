<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['semestres', 'carreras', 'especialidades', 'asignaturas', 'reticulas', 'grupos', 'laboratorios', 'tipos_dia_inhabil', 'dias_inhabiles'];
        foreach ([...$tables, 'horarios'] as $name) {
            if (Schema::hasTable('planificacion_'.$name) && DB::table('planificacion_'.$name)->exists()) {
                throw new RuntimeException('Populated Planning baseline requires explicit reconciliation; functional migration starts with new data.');
            }
        }
        $this->convertEmptyHolidayType();
        foreach ($tables as $name) {
            $tableName = 'planificacion_'.$name;
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) use ($name): void {
                    $this->identifier($table, 'id');
                    $table->primary('id');
                    if (in_array($name, ['semestres', 'carreras', 'especialidades', 'asignaturas', 'laboratorios', 'tipos_dia_inhabil'], true)) {
                        $table->string('codigo', 40)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
                        if ($name !== 'especialidades') {
                            $table->unique('codigo');
                        }
                    }
                    if ($name !== 'reticulas') {
                        $table->string('nombre', 100);
                    }
                    if ($name === 'carreras') {
                        $table->string('nombre_corto', 80)->nullable();
                    }
                    if ($name === 'asignaturas') {
                        $table->string('satca', 30)->nullable();
                    }
                    if (in_array($name, ['especialidades', 'reticulas', 'grupos', 'laboratorios'], true)) {
                        $this->reference($table, 'id_carrera', 'carreras', $name === 'laboratorios');
                    }
                    if ($name === 'especialidades') {
                        $table->unique(['id_carrera', 'codigo'], 'uq_plan_specialty');
                    }
                    if ($name === 'reticulas') {
                        $this->reference($table, 'id_especialidad', 'especialidades', true);
                        $this->reference($table, 'id_asignatura', 'asignaturas');
                    }
                    if ($name === 'grupos') {
                        $this->reference($table, 'id_semestre', 'semestres');
                        $table->unique(['id_semestre', 'id_carrera', 'nombre'], 'uq_plan_group');
                    }
                    if ($name === 'semestres') {
                        $table->date('fecha_inicio');
                        $table->date('fecha_fin');
                        $table->enum('estado', ['PLANIFICADO', 'ACTIVO', 'CERRADO', 'ARCHIVADO'])->default('PLANIFICADO');
                    } elseif ($name === 'laboratorios') {
                        $table->unsignedSmallInteger('capacidad');
                        $this->identifier($table, 'id_empleado_responsable', true);
                        $table->enum('estado', ['ACTIVO', 'MANTENIMIENTO', 'INACTIVO', 'ARCHIVADO'])->default('ACTIVO');
                    } else {
                        $table->boolean('activo')->default(true);
                    }
                    if ($name === 'dias_inhabiles') {
                        $this->reference($table, 'id_tipo_dia_inhabil', 'tipos_dia_inhabil');
                        $this->reference($table, 'id_laboratorio', 'laboratorios', true);
                        $table->dateTime('inicio_en', 6);
                        $table->dateTime('fin_en', 6);
                        $table->index(['id_laboratorio', 'inicio_en', 'fin_en'], 'idx_plan_holiday_window');
                    }
                });
            }
            foreach (['version', 'creado_en', 'actualizado_en'] as $column) {
                if (! Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, function (Blueprint $table) use ($column): void {
                        if ($column === 'version') {
                            $table->unsignedInteger($column)->default(1);
                        } else {
                            $table->dateTime($column, 6)->nullable();
                        }
                    });
                }
            }
        }
        foreach (['tipos_dia_inhabil', 'dias_inhabiles'] as $name) {
            if (! Schema::hasColumn('planificacion_'.$name, 'activo')) {
                Schema::table('planificacion_'.$name, fn (Blueprint $table) => $table->boolean('activo')->default(true));
            }
        }
        Schema::table('planificacion_semestres', function (Blueprint $table): void {
            $table->unsignedTinyInteger('activo_unico')->nullable()->storedAs("CASE WHEN estado = 'ACTIVO' THEN 1 ELSE NULL END");
            $table->unique('activo_unico', 'uq_plan_single_active');
        });
        Schema::table('planificacion_reticulas', function (Blueprint $table): void {
            $table->string('especialidad_unica', 26)->storedAs("COALESCE(id_especialidad, '')");
            $table->unique(['id_carrera', 'especialidad_unica', 'id_asignatura'], 'uq_plan_curriculum_null_safe');
        });
        Schema::create('planificacion_controles', function (Blueprint $table): void {
            $this->identifier($table, 'id');
            $table->primary('id');
            $table->string('codigo', 40)->unique();
        });
        DB::table('planificacion_controles')->insert(['id' => (string) Str::ulid(), 'codigo' => 'WRITER']);
        Schema::create('planificacion_claves_idempotencia', function (Blueprint $table): void {
            $this->identifier($table, 'id');
            $table->primary('id');
            $table->char('clave', 64)->unique();
            $table->char('hash_solicitud', 64);
            $table->longText('cuerpo_respuesta');
            $table->dateTime('expira_en', 6)->index();
        });
    }

    private function identifier(Blueprint $table, string $name, bool $nullable = false): void
    {
        $table->char($name, 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin')->nullable($nullable);
    }

    private function reference(Blueprint $table, string $column, string $target, bool $nullable = false): void
    {
        $this->identifier($table, $column, $nullable);
        $table->foreign($column, 'fk_plan_'.substr(hash('sha256', $table->getTable().$column), 0, 16))->references('id')->on('planificacion_'.$target)->restrictOnDelete();
    }

    private function convertEmptyHolidayType(): void
    {
        if (! Schema::hasTable('planificacion_tipos_dia_inhabil')) {
            return;
        }
        $column = collect(Schema::getColumns('planificacion_tipos_dia_inhabil'))->firstWhere('name', 'id');
        if (in_array($column['type_name'], ['char', 'varchar'], true)) {
            return;
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Numeric holiday catalog conversion requires the approved MySQL baseline.');
        }
        $references = [];
        foreach (Schema::getTables(DB::connection()->getDatabaseName()) as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $foreign) {
                if ($foreign['foreign_table'] === 'planificacion_tipos_dia_inhabil') {
                    if ($table['name'] !== 'planificacion_dias_inhabiles' || $foreign['columns'] !== ['id_tipo_dia_inhabil']) {
                        throw new RuntimeException('Unknown holiday catalog reference requires reconciliation.');
                    }
                    $references[] = $foreign['name'];
                }
            }
        }
        foreach ($references as $foreign) {
            Schema::table('planificacion_dias_inhabiles', fn (Blueprint $table) => $table->dropForeign($foreign));
        }
        DB::statement('ALTER TABLE planificacion_tipos_dia_inhabil MODIFY id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
        if (Schema::hasTable('planificacion_dias_inhabiles')) {
            DB::statement('ALTER TABLE planificacion_dias_inhabiles MODIFY id_tipo_dia_inhabil CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
            Schema::table('planificacion_dias_inhabiles', fn (Blueprint $table) => $table->foreign('id_tipo_dia_inhabil', 'fk_plan_holiday_type_ulid')->references('id')->on('planificacion_tipos_dia_inhabil')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Planning requires audited roll-forward or verified portable recovery.');
    }
};
