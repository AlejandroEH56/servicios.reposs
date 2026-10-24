<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLES = ['modalidades', 'programas_educativos', 'periodos', 'residentes', 'sectores', 'ramos', 'empresas', 'contactos_empresa', 'proyectos', 'asesores_internos_proyecto', 'asesores_externos_proyecto'];

    public function up(): void
    {
        $existing = Schema::getTables(DB::getDriverName() === 'mysql' ? DB::connection()->getDatabaseName() : null);
        foreach ($existing as $table) {
            if (str_starts_with($table['name'], 'residencias_') && DB::table($table['name'])->exists()) {
                throw new RuntimeException('Populated Residencies baseline requires reconciliation before functional adoption.');
            }
        }
        $this->convertCatalogs();
        foreach (self::TABLES as $kind) {
            $name = 'residencias_'.$kind;
            if (! Schema::hasTable($name)) {
                Schema::create($name, function (Blueprint $table) use ($kind): void {
                    $this->identifier($table, 'id');
                    $table->primary('id');
                    if (in_array($kind, ['modalidades', 'sectores', 'ramos', 'programas_educativos', 'periodos'], true)) {
                        $table->string('codigo', 40)->unique();
                        $table->string('nombre', 160);
                        if ($kind !== 'periodos') {
                            $table->boolean('activo')->default(true);
                        }
                    }
                    switch ($kind) {
                        case 'programas_educativos':
                            $this->reference($table, 'id_modalidad', 'modalidades');
                            $table->date('vigente_desde')->nullable();
                            $table->date('vigente_hasta')->nullable();
                            break;
                        case 'periodos':
                            $table->date('fecha_inicio');
                            $table->date('fecha_fin');
                            $table->unsignedTinyInteger('duracion_meses');
                            $table->unsignedTinyInteger('cantidad_reportes_parciales');
                            $table->unsignedTinyInteger('cantidad_reportes_finales')->default(1);
                            $table->string('estado', 20)->default('PLANIFICADO');
                            break;
                        case 'residentes':
                            $this->identifier($table, 'id_identidad', true);
                            $table->unique('id_identidad');
                            $this->reference($table, 'id_programa', 'programas_educativos', true);
                            $table->string('numero_control', 20)->unique();
                            $this->person($table);
                            $table->string('correo_institucional', 254)->nullable();
                            $table->string('domicilio', 500)->nullable();
                            $table->string('ciudad', 120)->nullable();
                            $table->string('numero_seguro_social', 30)->nullable();
                            $table->string('telefono', 30)->nullable();
                            $table->string('telefono_celular', 30)->nullable();
                            $table->string('curp', 20)->nullable()->unique();
                            $table->string('estado', 30)->default('REGISTRO_INICIAL');
                            $table->dateTime('eliminado_en', 6)->nullable();
                            break;
                        case 'empresas':
                            $this->reference($table, 'id_sector', 'sectores', true);
                            $this->reference($table, 'id_ramo', 'ramos', true);
                            $table->string('razon_social', 255)->index();
                            $table->string('rfc', 20)->charset('utf8mb4')->collation(DB::getDriverName() === 'mysql' ? 'utf8mb4_bin' : 'BINARY')->nullable()->unique();
                            $table->text('mision')->nullable();
                            $table->string('domicilio', 500)->nullable();
                            $table->string('ciudad', 120)->nullable();
                            $table->string('codigo_postal', 12)->nullable();
                            $table->string('telefono', 30)->nullable();
                            $table->string('correo', 254)->nullable();
                            $table->string('fax', 30)->nullable();
                            $table->string('estado', 20)->default('ACTIVO');
                            $table->dateTime('eliminado_en', 6)->nullable();
                            break;
                        case 'contactos_empresa':
                            $this->reference($table, 'id_empresa', 'empresas');
                            $this->person($table);
                            $table->string('nombre_puesto', 180)->nullable();
                            $table->string('nombre_grado', 120)->nullable();
                            $table->string('correo', 254)->nullable();
                            $table->string('telefono', 30)->nullable();
                            $table->boolean('activo')->default(true);
                            break;
                        case 'proyectos':
                            foreach (['residente' => 'residentes', 'periodo' => 'periodos', 'empresa' => 'empresas', 'programa' => 'programas_educativos'] as $column => $target) {
                                $this->reference($table, 'id_'.$column, $target);
                            }
                            $table->string('nombre', 255);
                            $table->unsignedSmallInteger('numero_vacantes')->default(1);
                            $table->date('fecha_inicio');
                            $table->date('fecha_fin');
                            $table->string('estado', 30)->default('BORRADOR');
                            $table->dateTime('eliminado_en', 6)->nullable();
                            $table->unique(['id_residente', 'id_periodo'], 'uq_res_s6_project_period');
                            $table->index(['id_residente', 'fecha_inicio', 'fecha_fin'], 'idx_res_s6_project_window');
                            break;
                        case 'asesores_internos_proyecto':
                        case 'asesores_externos_proyecto':
                            $this->reference($table, 'id_proyecto', 'proyectos');
                            if ($kind === 'asesores_internos_proyecto') {
                                $this->identifier($table, 'id_empleado');
                                $table->string('grado_instantanea', 120)->nullable();
                            } else {
                                $this->reference($table, 'id_contacto_empresa', 'contactos_empresa');
                            }
                            $table->string('codigo_rol', 40)->default('PRINCIPAL');
                            $table->string('nombre_instantanea', 500);
                            $table->string('puesto_instantanea', 180)->nullable();
                            $table->date('fecha_inicio');
                            $table->date('fecha_fin')->nullable();
                            $table->index(['id_proyecto', 'fecha_inicio', 'fecha_fin'], 'idx_res_s6_'.substr($kind, 9, 8).'_window');
                            break;
                    }
                });
            }
            foreach (['version', 'creado_en', 'actualizado_en'] as $column) {
                if (! Schema::hasColumn($name, $column)) {
                    Schema::table($name, function (Blueprint $table) use ($column): void {
                        if ($column === 'version') {
                            $table->unsignedInteger($column)->default(1);
                        } else {
                            $table->dateTime($column, 6)->nullable();
                        }
                    });
                }
            }
            if (in_array($kind, ['modalidades', 'sectores', 'ramos'], true) && ! Schema::hasColumn($name, 'activo')) {
                Schema::table($name, fn (Blueprint $table) => $table->boolean('activo')->default(true));
            }
        }
        if (! Schema::hasIndex('residencias_proyectos', 'idx_res_s6_project_window')) {
            Schema::table('residencias_proyectos', fn (Blueprint $table) => $table->index(['id_residente', 'fecha_inicio', 'fecha_fin'], 'idx_res_s6_project_window'));
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE residencias_empresas MODIFY rfc VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
            foreach (['asesores_internos_proyecto', 'asesores_externos_proyecto'] as $kind) {
                DB::statement('ALTER TABLE residencias_'.$kind.' MODIFY nombre_instantanea VARCHAR(500) NOT NULL');
            }
        }
        Schema::table('residencias_empresas', function (Blueprint $table): void {
            $this->identifier($table, 'id_identidad_creadora', true);
            $table->index('id_identidad_creadora');
        });
        Schema::create('residencias_controles', function (Blueprint $table): void {
            $this->identifier($table, 'id');
            $table->primary('id');
            $table->string('codigo', 40)->unique();
        });
        DB::table('residencias_controles')->insert(['id' => (string) Str::ulid(), 'codigo' => 'WRITER']);
        Schema::create('residencias_claves_idempotencia', function (Blueprint $table): void {
            $this->identifier($table, 'id');
            $table->primary('id');
            $table->char('clave', 64)->unique();
            $table->char('hash_solicitud', 64);
            $table->longText('cuerpo_respuesta');
            $table->dateTime('expira_en', 6)->index();
        });
    }

    private function person(Blueprint $table): void
    {
        $table->string('nombres', 160);
        $table->string('primer_apellido', 120);
        $table->string('segundo_apellido', 120)->nullable();
    }

    private function identifier(Blueprint $table, string $column, bool $nullable = false): void
    {
        $table->char($column, 26)->charset('ascii')->collation(DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY')->nullable($nullable);
    }

    private function reference(Blueprint $table, string $column, string $target, bool $nullable = false): void
    {
        $this->identifier($table, $column, $nullable);
        $table->foreign($column, 'fk_res_s6_'.substr(hash('sha256', $table->getTable().$column), 0, 18))->references('id')->on('residencias_'.$target)->restrictOnDelete();
    }

    private function convertCatalogs(): void
    {
        $mapping = ['residencias_modalidades' => ['residencias_programas_educativos' => 'id_modalidad'], 'residencias_sectores' => ['residencias_empresas' => 'id_sector'], 'residencias_ramos' => ['residencias_empresas' => 'id_ramo']];
        $conversions = [];
        foreach ($mapping as $catalog => $allowed) {
            if (! Schema::hasTable($catalog)) {
                continue;
            }
            $column = collect(Schema::getColumns($catalog))->firstWhere('name', 'id');
            if (in_array($column['type_name'], ['char', 'varchar'], true)) {
                continue;
            }
            if (DB::getDriverName() !== 'mysql') {
                throw new RuntimeException('Catalog conversion requires the approved MySQL baseline.');
            }
            $foreigns = [];
            foreach (Schema::getTables(DB::connection()->getDatabaseName()) as $table) {
                foreach (Schema::getForeignKeys($table['name']) as $foreign) {
                    if ($foreign['foreign_table'] !== $catalog) {
                        continue;
                    }
                    if (($allowed[$table['name']] ?? null) !== ($foreign['columns'][0] ?? null) || count($foreign['columns']) !== 1) {
                        throw new RuntimeException('Unknown Residency catalog reference requires reconciliation.');
                    }
                    if (DB::table($table['name'])->exists()) {
                        throw new RuntimeException('Populated catalog reference requires reconciliation.');
                    }
                    $foreigns[] = [$table['name'], $foreign['name'], $foreign['columns'][0]];
                }
            }
            $conversions[] = [$catalog, $allowed, $foreigns];
        }
        foreach ($conversions as [$catalog,$allowed,$foreigns]) {
            foreach ($foreigns as [$table,$foreign,$column]) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign));
            }
            DB::statement('ALTER TABLE '.$catalog.' MODIFY id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
            foreach ($allowed as $table => $column) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $nullable = $column === 'id_modalidad' ? 'NOT NULL' : 'NULL';
                DB::statement('ALTER TABLE '.$table.' MODIFY '.$column.' CHAR(26) CHARACTER SET ascii COLLATE ascii_bin '.$nullable);
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, 'fk_res_s6_'.substr(hash('sha256', $table.$column), 0, 18))->references('id')->on($catalog)->restrictOnDelete());
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Residencies requires audited roll-forward or verified portable recovery.');
    }
};
