<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private function identifier(Blueprint $table, string $name = 'id'): void
    {
        $table->char($name, 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
    }

    public function up(): void
    {
        $this->convertAcademicCatalog();
        if (! Schema::hasTable('organizacion_empleados')) {
            Schema::create('organizacion_empleados', function (Blueprint $table): void {
                $this->identifier($table);
                $table->primary('id');
                $this->identifier($table, 'id_identidad');
                $table->unique('id_identidad');
                $table->string('numero_empleado', 40)->nullable()->unique();
                $table->string('nombres', 160);
                $table->string('primer_apellido', 120);
                $table->string('segundo_apellido', 120)->nullable();
                $table->string('correo_institucional', 254)->nullable()->unique();
                $table->enum('estado', ['ACTIVO', 'EN_LICENCIA', 'INACTIVO', 'ARCHIVADO'])->default('ACTIVO');
                $table->dateTime('creado_en', 6);
                $table->dateTime('actualizado_en', 6);
            });
        }
        foreach (['version', 'version_perfil_microsoft'] as $column) {
            if (! Schema::hasColumn('organizacion_empleados', $column)) {
                Schema::table('organizacion_empleados', fn (Blueprint $table) => $table->unsignedInteger($column)->default($column === 'version' ? 1 : 0));
            }
        }
        if (! Schema::hasColumn('organizacion_empleados', 'habilitado_asesoria')) {
            Schema::table('organizacion_empleados', fn (Blueprint $table) => $table->boolean('habilitado_asesoria')->default(false));
        }
        if (! Schema::hasTable('organizacion_unidades')) {
            Schema::create('organizacion_unidades', function (Blueprint $table): void {
                $this->identifier($table);
                $table->primary('id');
                $table->char('id_padre', 26)->nullable()->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
                $table->string('codigo', 60)->unique();
                $table->string('nombre', 180);
                $table->string('tipo_unidad', 40);
                $table->boolean('activo')->default(true);
                $table->dateTime('creado_en', 6);
                $table->dateTime('actualizado_en', 6);
                $table->foreign('id_padre')->references('id')->on('organizacion_unidades');
            });
        }
        if (! Schema::hasColumn('organizacion_unidades', 'version')) {
            Schema::table('organizacion_unidades', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        }
        if (! Schema::hasTable('organizacion_asignaciones_puesto')) {
            Schema::create('organizacion_asignaciones_puesto', function (Blueprint $table): void {
                $this->identifier($table);
                $table->primary('id');
                $this->identifier($table, 'id_empleado');
                $table->char('id_unidad_organizacional', 26)->nullable()->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
                $table->string('codigo_puesto', 80)->nullable();
                $table->string('nombre_puesto', 180);
                $table->enum('origen_dato', ['MICROSOFT_JOBTITLE', 'MANUAL', 'MIGRACION']);
                $table->string('cadena_origen', 1000)->nullable();
                $table->unsignedSmallInteger('orden_en_origen')->nullable();
                $table->date('fecha_inicio');
                $table->date('fecha_fin')->nullable();
                $table->dateTime('creado_en', 6);
                $table->dateTime('actualizado_en', 6);
                $table->foreign('id_empleado')->references('id')->on('organizacion_empleados');
                $table->foreign('id_unidad_organizacional', 'fk_org_puesto_unidad')->references('id')->on('organizacion_unidades');
                $table->index(['id_empleado', 'fecha_fin'], 'idx_org_puesto_empleado_active');
            });
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE organizacion_asignaciones_puesto MODIFY id_unidad_organizacional CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL');
        }
        if (! Schema::hasTable('organizacion_niveles_academicos')) {
            Schema::create('organizacion_niveles_academicos', function (Blueprint $table): void {
                $this->identifier($table);
                $table->primary('id');
                $table->string('codigo', 40)->unique();
                $table->string('nombre', 100);
                $table->unsignedSmallInteger('orden_jerarquia')->unique();
            });
        }
        if (! Schema::hasColumn('organizacion_niveles_academicos', 'version')) {
            Schema::table('organizacion_niveles_academicos', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        }
        if (! Schema::hasTable('organizacion_grados_academicos')) {
            Schema::create('organizacion_grados_academicos', function (Blueprint $table): void {
                $this->identifier($table);
                $table->primary('id');
                $this->identifier($table, 'id_empleado');
                $this->identifier($table, 'id_nivel_academico');
                $table->string('nombre_programa');
                $table->string('siglas', 45)->nullable();
                $table->date('fecha_obtencion')->nullable();
                $table->dateTime('creado_en', 6);
                $table->dateTime('actualizado_en', 6);
                $table->foreign('id_empleado')->references('id')->on('organizacion_empleados');
                $table->foreign('id_nivel_academico', 'fk_org_grado_nivel')->references('id')->on('organizacion_niveles_academicos');
                $table->index(['id_empleado', 'id_nivel_academico'], 'idx_org_grado_empleado');
            });
        }
        Schema::create('organizacion_controles', function (Blueprint $table): void {
            $this->identifier($table);
            $table->primary('id');
            $table->string('codigo', 30)->unique();
        });
        DB::table('organizacion_controles')->insert(['id' => (string) Str::ulid(), 'codigo' => 'TREE']);
    }

    private function convertAcademicCatalog(): void
    {
        if (! Schema::hasTable('organizacion_niveles_academicos')) {
            return;
        }
        $columns = collect(Schema::getColumns('organizacion_niveles_academicos'))->keyBy('name');
        if (in_array($columns['id']['type_name'], ['char', 'varchar'], true)) {
            return;
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Numeric academic catalog conversion requires the approved MySQL baseline.');
        }
        $references = [];
        foreach (Schema::getTables(DB::connection()->getDatabaseName()) as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $foreign) {
                if ($foreign['foreign_table'] === 'organizacion_niveles_academicos') {
                    if ($table['name'] !== 'organizacion_grados_academicos' || $foreign['columns'] !== ['id_nivel_academico']) {
                        throw new RuntimeException('Unknown academic catalog reference requires reconciliation.');
                    }
                    $references[] = $foreign['name'];
                }
            }
        }
        if (! Schema::hasColumn('organizacion_niveles_academicos', 'id_ulid')) {
            Schema::table('organizacion_niveles_academicos', function (Blueprint $table): void {
                $table->char('id_ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable();
            });
        }
        foreach (DB::table('organizacion_niveles_academicos')->whereNull('id_ulid')->get() as $level) {
            DB::table('organizacion_niveles_academicos')->where('id', $level->id)->update(['id_ulid' => (string) Str::ulid()]);
        }
        if (! Schema::hasTable('migracion_mapa_ids')) {
            throw new RuntimeException('Academic baseline conversion requires its migration ID ledger.');
        }
        foreach (DB::table('organizacion_niveles_academicos')->get() as $level) {
            DB::table('migracion_mapa_ids')->updateOrInsert([
                'base_origen' => DB::connection()->getDatabaseName(), 'tabla_origen' => 'organizacion_niveles_academicos', 'id_legacy' => (string) $level->id,
            ], ['contexto_destino' => 'Organization', 'tabla_destino' => 'organizacion_niveles_academicos', 'id_destino' => $level->id_ulid, 'migrado_en' => now()]);
        }
        $hasDegrees = Schema::hasTable('organizacion_grados_academicos');
        if ($hasDegrees) {
            if (DB::table('organizacion_grados_academicos as degree')->leftJoin('organizacion_niveles_academicos as level', 'level.id', '=', 'degree.id_nivel_academico')->whereNull('level.id')->exists()) {
                throw new RuntimeException('Academic degrees contain unmapped levels.');
            }
            if (! Schema::hasColumn('organizacion_grados_academicos', 'id_nivel_academico_ulid')) {
                Schema::table('organizacion_grados_academicos', function (Blueprint $table): void {
                    $table->char('id_nivel_academico_ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable();
                });
            }
            DB::statement('UPDATE organizacion_grados_academicos d JOIN organizacion_niveles_academicos l ON l.id=d.id_nivel_academico SET d.id_nivel_academico_ulid=l.id_ulid');
            foreach ($references as $name) {
                Schema::table('organizacion_grados_academicos', fn (Blueprint $table) => $table->dropForeign($name));
            }
            Schema::table('organizacion_grados_academicos', fn (Blueprint $table) => $table->dropColumn('id_nivel_academico'));
            Schema::table('organizacion_grados_academicos', fn (Blueprint $table) => $table->renameColumn('id_nivel_academico_ulid', 'id_nivel_academico'));
        }
        DB::statement('ALTER TABLE organizacion_niveles_academicos MODIFY id SMALLINT UNSIGNED NOT NULL');
        Schema::table('organizacion_niveles_academicos', fn (Blueprint $table) => $table->dropPrimary());
        Schema::table('organizacion_niveles_academicos', fn (Blueprint $table) => $table->dropColumn('id'));
        Schema::table('organizacion_niveles_academicos', fn (Blueprint $table) => $table->renameColumn('id_ulid', 'id'));
        DB::statement('ALTER TABLE organizacion_niveles_academicos MODIFY id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, ADD PRIMARY KEY (id)');
        if ($hasDegrees) {
            DB::statement('ALTER TABLE organizacion_grados_academicos MODIFY id_nivel_academico CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
            Schema::table('organizacion_grados_academicos', fn (Blueprint $table) => $table->foreign('id_nivel_academico')->references('id')->on('organizacion_niveles_academicos'));
            Schema::table('organizacion_grados_academicos', fn (Blueprint $table) => $table->index(['id_empleado', 'id_nivel_academico'], 'idx_org_grado_empleado_nivel'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Organization requires roll-forward and a verified backup before migration.');
    }
};
