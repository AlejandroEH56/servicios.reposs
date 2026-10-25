<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['definiciones_requisito', 'entregas_evidencia', 'revisiones_evidencia', 'archivos_preparados'] as $kind) {
            if (Schema::hasTable('residencias_'.$kind) && DB::table('residencias_'.$kind)->exists()) {
                throw new RuntimeException('Populated evidence baseline requires reconciliation before adoption.');
            }
        }
        if (! Schema::hasTable('residencias_definiciones_requisito')) {
            Schema::create('residencias_definiciones_requisito', function (Blueprint $table): void {
                $this->id($table, 'id');
                $table->primary('id');
                $this->reference($table, 'id_programa', 'programas_educativos', true);
                $table->char('alcance_programa', 26)->charset('ascii')->collation(DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY')->storedAs("COALESCE(id_programa, '00000000000000000000000000')");
                $table->string('codigo', 80);
                $table->string('nombre', 255);
                $table->string('etapa', 30);
                $table->string('tipo_evidencia', 30)->default('DOCUMENTO');
                $table->boolean('obligatorio')->default(true);
                $table->unsignedSmallInteger('orden_visualizacion')->default(0);
                $table->date('vigente_desde')->nullable();
                $table->date('vigente_hasta')->nullable();
                $table->boolean('activo')->default(true);
                $table->unique(['codigo', 'alcance_programa'], 'uq_res_s7_definition');
                $table->index(['etapa', 'activo'], 'idx_res_s7_definition_stage');
            });
        }
        $this->add('definiciones_requisito', 'alcance', fn (Blueprint $t) => $t->string('alcance', 20)->default('RESIDENTE'));
        $this->add('definiciones_requisito', 'mimes_permitidos', fn (Blueprint $t) => $t->text('mimes_permitidos')->nullable());
        $this->add('definiciones_requisito', 'tamano_maximo', fn (Blueprint $t) => $t->unsignedInteger('tamano_maximo')->default(10485760));
        $this->add('definiciones_requisito', 'version', fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        $this->add('definiciones_requisito', 'creado_en', fn (Blueprint $t) => $t->dateTime('creado_en', 6)->nullable());
        $this->add('definiciones_requisito', 'actualizado_en', fn (Blueprint $t) => $t->dateTime('actualizado_en', 6)->nullable());
        if (! Schema::hasTable('residencias_entregas_evidencia')) {
            Schema::create('residencias_entregas_evidencia', function (Blueprint $table): void {
                $this->id($table, 'id');
                $table->primary('id');
                $this->reference($table, 'id_requisito', 'definiciones_requisito');
                $this->reference($table, 'id_residente', 'residentes');
                $this->reference($table, 'id_proyecto', 'proyectos', true);
                $table->char('id_alcance', 26)->charset('ascii')->collation(DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY')->storedAs('COALESCE(id_proyecto, id_residente)');
                $this->id($table, 'id_archivo');
                $table->unique('id_archivo');
                $table->unsignedSmallInteger('numero_version');
                $table->string('estado', 30)->default('ENVIADO');
                $table->dateTime('enviado_en', 6);
                $table->dateTime('reemplazado_en', 6)->nullable();
                $table->dateTime('creado_en', 6);
                $table->unique(['id_requisito', 'id_alcance', 'numero_version'], 'uq_res_s7_submission_version');
                $table->index(['id_residente', 'estado'], 'idx_res_s7_submission_resident');
                $table->index(['id_proyecto', 'estado'], 'idx_res_s7_submission_project');
            });
        }
        $this->add('entregas_evidencia', 'id_identidad_envio', fn (Blueprint $t) => $this->id($t, 'id_identidad_envio', true));
        $this->add('entregas_evidencia', 'instantanea_requisito', fn (Blueprint $t) => $t->text('instantanea_requisito')->nullable());
        $this->add('entregas_evidencia', 'nombre_original', fn (Blueprint $t) => $t->string('nombre_original', 180)->nullable());
        $this->add('entregas_evidencia', 'tipo_mime', fn (Blueprint $t) => $t->string('tipo_mime', 100)->nullable());
        $this->add('entregas_evidencia', 'tamano_bytes', fn (Blueprint $t) => $t->unsignedInteger('tamano_bytes')->nullable());
        $this->add('entregas_evidencia', 'sha256', fn (Blueprint $t) => $t->char('sha256', 64)->nullable());
        $this->add('entregas_evidencia', 'version', fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        $this->add('entregas_evidencia', 'cantidad_revisiones', fn (Blueprint $t) => $t->unsignedInteger('cantidad_revisiones')->default(0));
        if (! Schema::hasTable('residencias_revisiones_evidencia')) {
            Schema::create('residencias_revisiones_evidencia', function (Blueprint $table): void {
                $this->id($table, 'id');
                $table->primary('id');
                $this->reference($table, 'id_entrega', 'entregas_evidencia');
                $this->id($table, 'id_identidad_revisor');
                $table->unsignedInteger('numero_revision');
                $table->string('estado_anterior', 30)->nullable();
                $table->string('decision', 20);
                $table->text('observaciones')->nullable();
                $table->decimal('calificacion', 5, 2)->nullable();
                $table->dateTime('decidido_en', 6);
                $table->dateTime('creado_en', 6);
                $table->unique(['id_entrega', 'numero_revision'], 'uq_res_s7_review_number');
                $table->index(['id_entrega', 'decidido_en'], 'idx_res_s7_review_history');
            });
        } elseif (DB::getDriverName() === 'mysql') {
            foreach (Schema::getForeignKeys('residencias_revisiones_evidencia') as $foreign) {
                if ($foreign['columns'] === ['id_entrega']) {
                    Schema::table('residencias_revisiones_evidencia', fn (Blueprint $t) => $t->dropForeign($foreign['name']));
                    Schema::table('residencias_revisiones_evidencia', fn (Blueprint $t) => $t->foreign('id_entrega')->references('id')->on('residencias_entregas_evidencia')->restrictOnDelete());
                }
            }
        }
        if (! Schema::hasTable('residencias_archivos_preparados')) {
            Schema::create('residencias_archivos_preparados', function (Blueprint $table): void {
                $this->id($table, 'id');
                $table->primary('id');
                $table->char('clave', 64)->unique();
                $table->char('hash_solicitud', 64);
                $this->reference($table, 'id_requisito', 'definiciones_requisito');
                $this->reference($table, 'id_residente', 'residentes');
                $this->reference($table, 'id_proyecto', 'proyectos', true);
                $this->id($table, 'id_identidad_carga');
                $this->id($table, 'id_archivo', true);
                $table->unique('id_archivo');
                $table->unsignedInteger('version_requisito');
                $table->string('estado', 20);
                $table->string('nombre_original', 180);
                $table->string('tipo_mime', 100)->nullable();
                $table->unsignedInteger('tamano_bytes')->nullable();
                $table->char('sha256', 64)->nullable();
                $table->dateTime('expira_en', 6);
                $table->dateTime('creado_en', 6);
                $table->dateTime('actualizado_en', 6);
                $table->dateTime('utilizado_en', 6)->nullable();
                $table->index(['estado', 'expira_en'], 'idx_res_s7_prepared_expiry');
            });
        }
    }

    private function id(Blueprint $table, string $name, bool $nullable = false): void
    {
        $table->char($name, 26)->charset('ascii')->collation(DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY')->nullable($nullable);
    }

    private function reference(Blueprint $table, string $name, string $target, bool $nullable = false): void
    {
        $this->id($table, $name, $nullable);
        $table->foreign($name)->references('id')->on('residencias_'.$target)->restrictOnDelete();
    }

    private function add(string $kind, string $column, Closure $definition): void
    {
        if (! Schema::hasColumn('residencias_'.$kind, $column)) {
            Schema::table('residencias_'.$kind, $definition);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Evidence history requires roll-forward or the rehearsed isolated recovery.');
    }
};
