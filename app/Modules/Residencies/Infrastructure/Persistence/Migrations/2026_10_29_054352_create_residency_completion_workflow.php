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
        foreach (['reportes_avance', 'liberaciones', 'historial_liberaciones', 'plantillas_documento', 'recursos_plantilla', 'documentos_emitidos', 'constancias',
            'reaperturas_evidencia', 'anulaciones_documento', 'secuencias_folio', 'recursos_preparados', 'proyectos_constancia'] as $kind) {
            if (Schema::hasTable('residencias_'.$kind) && DB::table('residencias_'.$kind)->exists()) {
                throw new RuntimeException('Populated completion baseline requires reconciliation before adoption.');
            }
        }
        $this->add('definiciones_requisito', 'id_proyecto', fn (Blueprint $t) => $this->reference($t, 'id_proyecto', 'proyectos', true));
        $this->add('entregas_evidencia', 'dependencias_reporte', fn (Blueprint $t) => $t->text('dependencias_reporte')->nullable());
        $this->add('residentes', 'tipo_seguro', fn (Blueprint $t) => $t->string('tipo_seguro', 20)->nullable());
        $this->add('empresas', 'telefono_celular', fn (Blueprint $t) => $t->string('telefono_celular', 30)->nullable());
        $this->add('proyectos', 'procedencia', fn (Blueprint $t) => $t->string('procedencia', 30)->nullable());
        $this->add('proyectos', 'aprobado_en', fn (Blueprint $t) => $t->dateTime('aprobado_en', 6)->nullable());
        $this->add('proyectos', 'id_identidad_aprobador', fn (Blueprint $t) => $this->id($t, 'id_identidad_aprobador', true));
        $oldReports = Schema::hasTable('residencias_reportes_avance');
        $this->create('reportes_avance', function (Blueprint $t): void {
            $this->reference($t, 'id_proyecto', 'proyectos');
            $this->reference($t, 'id_entrega', 'entregas_evidencia', true);
            $t->string('tipo_reporte', 20);
            $t->unsignedSmallInteger('numero_consecutivo');
            $t->string('titulo', 255);
            $t->dateTime('entregado_en', 6)->nullable();
            $t->dateTime('creado_en', 6);
            $t->unique(['id_proyecto', 'tipo_reporte', 'numero_consecutivo'], 'uq_res_s8_report_sequence');
            $t->unique('id_entrega');
        });
        if ($oldReports) {
            Schema::table('residencias_reportes_avance', function (Blueprint $t): void {
                $t->char('id_entrega', 26)->charset('ascii')->collation('ascii_bin')->nullable()->change();
                $t->dateTime('entregado_en', 6)->nullable()->change();
            });
        }
        $this->add('reportes_avance', 'id_requisito', fn (Blueprint $t) => $this->reference($t, 'id_requisito', 'definiciones_requisito'));
        $this->add('reportes_avance', 'fecha_inicio', fn (Blueprint $t) => $t->date('fecha_inicio'));
        $this->add('reportes_avance', 'fecha_fin', fn (Blueprint $t) => $t->date('fecha_fin'));
        $this->add('reportes_avance', 'version', fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        $this->create('liberaciones', function (Blueprint $t): void {
            $this->reference($t, 'id_proyecto', 'proyectos');
            $t->unique('id_proyecto');
            $this->reference($t, 'id_reporte_final', 'reportes_avance');
            $t->unique('id_reporte_final');
            $t->string('estado', 30)->default('PENDIENTE');
            $t->string('resolucion', 255)->nullable();
            $t->text('observaciones')->nullable();
            $this->id($t, 'id_identidad_resolutor', true);
            $t->dateTime('resuelto_en', 6)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('creado_en', 6);
            $t->dateTime('actualizado_en', 6);
        });
        $this->add('liberaciones', 'datos_elegibilidad', fn (Blueprint $t) => $t->longText('datos_elegibilidad')->nullable());
        $this->create('historial_liberaciones', function (Blueprint $t): void {
            $this->reference($t, 'id_liberacion', 'liberaciones');
            $t->unsignedInteger('numero_cambio');
            $t->string('estado_anterior', 30)->nullable();
            $t->string('estado_nuevo', 30);
            $t->string('resolucion', 255)->nullable();
            $t->text('observaciones');
            $this->id($t, 'id_identidad_actor');
            $t->dateTime('cambiado_en', 6);
            $t->unique(['id_liberacion', 'numero_cambio'], 'uq_res_s8_release_change');
        });
        $this->add('historial_liberaciones', 'datos_elegibilidad', fn (Blueprint $t) => $t->longText('datos_elegibilidad')->nullable());
        $this->create('reaperturas_evidencia', function (Blueprint $t): void {
            $this->reference($t, 'id_entrega', 'entregas_evidencia');
            $t->unsignedInteger('numero_reapertura');
            $t->unsignedInteger('numero_revision_anterior');
            $t->string('estado_anterior', 30);
            $t->text('observaciones');
            $this->id($t, 'id_identidad_actor');
            $t->dateTime('creado_en', 6);
            $t->unique(['id_entrega', 'numero_reapertura'], 'uq_res_s8_reopening');
        });
        $this->create('plantillas_documento', function (Blueprint $t): void {
            $t->string('codigo', 80);
            $t->string('nombre', 160);
            $t->text('descripcion')->nullable();
            $this->id($t, 'id_archivo_plantilla', true);
            $t->unsignedSmallInteger('numero_version');
            $t->boolean('activo')->default(false);
            $t->dateTime('creado_en', 6);
            $t->dateTime('actualizado_en', 6);
            $t->unique(['codigo', 'numero_version'], 'uq_res_s8_template_version');
        });
        $this->add('plantillas_documento', 'tipo_documento', fn (Blueprint $t) => $t->string('tipo_documento', 50));
        $this->add('plantillas_documento', 'configuracion', fn (Blueprint $t) => $t->text('configuracion'));
        $oldResources = Schema::hasTable('residencias_recursos_plantilla');
        $this->create('recursos_plantilla', function (Blueprint $t): void {
            $this->reference($t, 'id_plantilla', 'plantillas_documento');
            $this->id($t, 'id_archivo', true);
            $t->string('rol_recurso', 60);
            $t->unsignedSmallInteger('orden_visualizacion')->default(0);
            $t->boolean('activo')->default(true);
            $t->unique(['id_plantilla', 'id_archivo'], 'uq_res_s8_template_resource');
        });
        if ($oldResources) {
            Schema::table('residencias_recursos_plantilla', fn (Blueprint $t) => $t->char('id_archivo', 26)->charset('ascii')->collation('ascii_bin')->nullable()->change());
        }
        $this->add('recursos_plantilla', 'clave_integrada', fn (Blueprint $t) => $t->string('clave_integrada', 100)->nullable());
        $oldDocuments = Schema::hasTable('residencias_documentos_emitidos');
        $this->create('documentos_emitidos', function (Blueprint $t): void {
            $this->reference($t, 'id_residente', 'residentes');
            $this->reference($t, 'id_proyecto', 'proyectos', true);
            $this->reference($t, 'id_plantilla', 'plantillas_documento', true);
            $t->string('tipo_documento', 50);
            $t->unsignedSmallInteger('numero_version_plantilla')->nullable();
            $t->json('datos_emision');
            $t->char('hash_datos_emision', 64);
            $this->id($t, 'id_archivo', true);
            $t->unique('id_archivo');
            $this->id($t, 'id_identidad_emisor');
            $t->dateTime('emitido_en', 6);
            $t->dateTime('anulado_en', 6)->nullable();
            $t->string('motivo_anulacion', 500)->nullable();
            $t->index(['id_proyecto', 'emitido_en'], 'idx_res_s8_document_project');
        });
        if ($oldDocuments) {
            Schema::table('residencias_documentos_emitidos', fn (Blueprint $t) => $t->char('id_archivo', 26)->charset('ascii')->collation('ascii_bin')->nullable()->change());
        }
        $this->add('documentos_emitidos', 'html_emision', fn (Blueprint $t) => $t->longText('html_emision'));
        $this->add('documentos_emitidos', 'hash_html', fn (Blueprint $t) => $t->char('hash_html', 64));
        $this->add('documentos_emitidos', 'estado', fn (Blueprint $t) => $t->string('estado', 20)->default('PENDIENTE'));
        $this->add('documentos_emitidos', 'version', fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        $this->add('documentos_emitidos', 'sha256_archivo', fn (Blueprint $t) => $t->char('sha256_archivo', 64)->nullable());
        $this->add('documentos_emitidos', 'tamano_archivo', fn (Blueprint $t) => $t->unsignedInteger('tamano_archivo')->nullable());
        $this->add('documentos_emitidos', 'bloqueado_por', fn (Blueprint $t) => $t->string('bloqueado_por', 36)->nullable());
        $this->add('documentos_emitidos', 'bloqueado_hasta', fn (Blueprint $t) => $t->dateTime('bloqueado_hasta', 6)->nullable());
        $this->add('documentos_emitidos', 'ultimo_error', fn (Blueprint $t) => $t->string('ultimo_error', 60)->nullable());
        $this->create('anulaciones_documento', function (Blueprint $t): void {
            $this->reference($t, 'id_documento', 'documentos_emitidos');
            $t->unique('id_documento');
            $t->text('motivo');
            $t->string('origen', 30);
            $this->id($t, 'id_identidad_actor');
            $t->dateTime('creado_en', 6);
        });
        $this->create('constancias', function (Blueprint $t): void {
            $this->reference($t, 'id_proyecto', 'proyectos');
            $this->reference($t, 'id_asignacion_asesor_interno', 'asesores_internos_proyecto');
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('numero_periodo');
            $t->unsignedInteger('numero_consecutivo');
            $t->string('folio', 80)->unique();
            $t->date('rango_inicio')->nullable();
            $t->date('rango_fin')->nullable();
            $this->id($t, 'id_identidad_generador');
            $this->reference($t, 'id_documento_emitido', 'documentos_emitidos');
            $t->unique('id_documento_emitido');
            $t->dateTime('generado_en', 6);
            $t->unique(['anio', 'numero_periodo', 'numero_consecutivo'], 'uq_res_s8_certificate_sequence');
        });
        $this->create('proyectos_constancia', function (Blueprint $t): void {
            $this->reference($t, 'id_constancia', 'constancias');
            $this->reference($t, 'id_proyecto', 'proyectos');
            $t->unique(['id_constancia', 'id_proyecto'], 'uq_res_s8_certificate_project');
        });
        $this->create('secuencias_folio', function (Blueprint $t): void {
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('numero_periodo');
            $t->unsignedInteger('ultimo_numero')->default(0);
            $t->unique(['anio', 'numero_periodo'], 'uq_res_s8_folio_allocator');
        });
        $this->create('recursos_preparados', function (Blueprint $t): void {
            $t->char('clave', 64)->unique();
            $t->char('hash_solicitud', 64);
            $this->id($t, 'id_identidad_carga');
            $this->id($t, 'id_archivo', true);
            $t->string('estado', 20);
            $t->string('tipo_mime', 100);
            $t->unsignedInteger('tamano_bytes');
            $t->char('sha256', 64);
            $t->dateTime('expira_en', 6);
            $t->dateTime('creado_en', 6);
            $t->dateTime('utilizado_en', 6)->nullable();
        });
        foreach (['SOLICITUD_RESIDENCIA', 'CARTA_PRESENTACION_FORMAL', 'CARTA_PRESENTACION_INFORMAL', 'FORMATO_REPORTE_PARCIAL', 'FORMATO_REPORTE_FINAL', 'CONSTANCIA_ASESORIA'] as $type) {
            DB::table('residencias_plantillas_documento')->insert(['id' => (string) Str::ulid(), 'codigo' => $type, 'nombre' => $type, 'tipo_documento' => $type,
                'numero_version' => 1, 'activo' => false, 'configuracion' => json_encode(['institution' => 'INSTITUTO TECNOLÓGICO SUPERIOR DE HUATUSCO',
                    'institutionKey' => 'CLAVE 30EIT0013Z', 'city' => 'Huatusco de Chicuellar, Ver.', 'departmentTitle' => 'Departamento de Residencias Profesionales y Servicio Social',
                    'divisionTitle' => 'División de Carrera', 'departmentSignerId' => null, 'divisionSignerId' => null], JSON_THROW_ON_ERROR),
                'creado_en' => now(), 'actualizado_en' => now()]);
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

    private function create(string $kind, Closure $definition): void
    {
        if (Schema::hasTable('residencias_'.$kind)) {
            return;
        }
        Schema::create('residencias_'.$kind, function (Blueprint $table) use ($definition): void {
            $this->id($table, 'id');
            $table->primary('id');
            $definition($table);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Completion history requires roll-forward or the rehearsed isolated recovery.');
    }
};
