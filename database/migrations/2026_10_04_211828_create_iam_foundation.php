<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('iam_identidades')) {
            Schema::create('iam_identidades', function (Blueprint $table) {
                $table->char('id', 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin')->primary();
                $table->string('nombre_mostrado');
                $table->string('correo_normalizado', 254)->nullable()->unique('uq_iam_identidad_correo');
                $table->enum('estado', ['PENDIENTE', 'ACTIVA', 'SUSPENDIDA', 'DESACTIVADA'])->default('PENDIENTE');
                $table->dateTime('ultimo_acceso_en', 6)->nullable();
                $table->dateTime('creado_en', 6);
                $table->dateTime('actualizado_en', 6);
                $table->dateTime('deshabilitado_en', 6)->nullable();
            });
        } else {
            if (DB::getDriverName() !== 'mysql'
                || DB::table('iam_identidades')->whereNotIn('estado', ['PENDIENTE', 'ACTIVA', 'SUSPENDIDA', 'DESACTIVADA'])->exists()) {
                throw new RuntimeException('IAM baseline requires an approved data-state conversion before migration.');
            }
            $checks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'iam_identidades' AND CONSTRAINT_TYPE = 'CHECK'");
            foreach ($checks as $check) {
                if ($check->CONSTRAINT_NAME === 'chk_iam_identidad_estado') {
                    DB::statement('ALTER TABLE iam_identidades DROP CHECK chk_iam_identidad_estado');
                }
            }
            DB::statement("ALTER TABLE iam_identidades ALTER COLUMN estado SET DEFAULT 'PENDIENTE'");
            DB::statement("ALTER TABLE iam_identidades ADD CONSTRAINT chk_iam_identidad_estado CHECK (estado IN ('PENDIENTE','ACTIVA','SUSPENDIDA','DESACTIVADA'))");
        }
        if (! Schema::hasTable('iam_cuentas_externas')) {
            Schema::create('iam_cuentas_externas', function (Blueprint $table) {
                $table->char('id', 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin')->primary();
                $table->char('id_identidad', 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
                $table->string('proveedor', 40);
                $table->string('id_inquilino', 80);
                $table->string('sujeto_proveedor', 128);
                $table->string('nombre_principal', 254)->nullable();
                $table->json('instantanea_atributos')->nullable();
                $table->dateTime('creado_en', 6);
                $table->dateTime('actualizado_en', 6);
                $table->unique(['proveedor', 'id_inquilino', 'sujeto_proveedor'], 'uq_iam_externo_asignatura');
                $table->foreign('id_identidad')->references('id')->on('iam_identidades');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('IAM uses roll-forward; disposable tests may use guarded migrate:fresh.');
    }
};
