<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compartido_mensajes_salida') && ! Schema::hasColumn('compartido_mensajes_salida', 'estado')
            && DB::table('compartido_mensajes_salida')->exists()) {
            throw new RuntimeException('Existing outbox rows require reviewed envelope/state adoption.');
        }
        if (Schema::hasTable('compartido_registros_auditoria')) {
            $column = collect(Schema::getColumns('compartido_registros_auditoria'))->firstWhere('name', 'id');
            if ($column && ! in_array($column['type_name'], ['char', 'varchar'], true)) {
                if (DB::table('compartido_registros_auditoria')->exists()) {
                    throw new RuntimeException('Existing numeric audit identifiers require a separate reviewed conversion.');
                }
                DB::statement('ALTER TABLE compartido_registros_auditoria MODIFY id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
            }
        } else {
            Schema::create('compartido_registros_auditoria', function (Blueprint $table) {
                $table->char('id', 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin')->primary();
                $table->dateTime('ocurrido_en', 6);
                $table->char('id_identidad_actor', 26)->nullable();
                $table->string('nombre_contexto', 60);
                $table->string('accion', 120);
                $table->string('tipo_sujeto', 100);
                $table->string('id_sujeto', 100);
                $table->char('id_correlacion', 36)->nullable();
                $table->json('metadatos')->nullable();
            });
        }
        if (! Schema::hasTable('compartido_mensajes_salida')) {
            Schema::create('compartido_mensajes_salida', function (Blueprint $table) {
                $table->char('id', 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin')->primary();
                $table->string('nombre_contexto', 60);
                $table->string('tipo_agregado', 100);
                $table->char('id_agregado', 26);
                $table->string('tipo_evento', 160);
                $table->json('contenido');
                $table->dateTime('ocurrido_en', 6);
                $table->dateTime('publicado_en', 6)->nullable();
                $table->unsignedSmallInteger('intentos')->default(0);
                $table->text('ultimo_error')->nullable();
            });
        }
        if (! Schema::hasColumn('compartido_mensajes_salida', 'estado')) {
            if (DB::table('compartido_mensajes_salida')->exists()) {
                throw new RuntimeException('Existing outbox rows require reviewed envelope/state adoption.');
            }
            Schema::table('compartido_mensajes_salida', function (Blueprint $table) {
                $table->string('estado', 20)->default('PENDING');
                $table->dateTime('disponible_en', 6)->nullable();
                $table->dateTime('bloqueado_hasta', 6)->nullable();
                $table->string('bloqueado_por', 36)->nullable();
                $table->dateTime('fallido_en', 6)->nullable();
                $table->index(['estado', 'disponible_en', 'bloqueado_hasta'], 'idx_outbox_claim');
            });
        }
        if (! Schema::hasTable('compartido_bandeja_entrada')) {
            Schema::create('compartido_bandeja_entrada', function (Blueprint $table) {
                $table->string('consumidor', 100);
                $table->char('id_evento', 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
                $table->dateTime('procesado_en', 6);
                $table->primary(['consumidor', 'id_evento']);
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Shared uses roll-forward; fresh is restricted to disposable tests.');
    }
};
