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
        if (Schema::hasTable('laboratorios_decisiones_solicitud') && DB::table('laboratorios_decisiones_solicitud')->exists()) {
            throw new RuntimeException('Existing decisions require reconciliation before activation.');
        }
        if (! Schema::hasTable('laboratorios_decisiones_solicitud')) {
            Schema::create('laboratorios_decisiones_solicitud', function (Blueprint $table): void {
                $this->identifier($table, 'id')->primary();
                $this->identifier($table, 'id_solicitud');
                $this->identifier($table, 'id_identidad_decisor');
                $table->string('decision', 20);
                $table->text('observaciones')->nullable();
                $table->dateTime('decidido_en', 6);
                $table->foreign('id_solicitud')->references('id')->on('laboratorios_solicitudes')->restrictOnDelete();
            });
        }
        Schema::table('laboratorios_decisiones_solicitud', function (Blueprint $table): void {
            $table->unsignedInteger('version_solicitud');
            $table->string('estado_anterior', 20);
            $table->longText('instantanea');
            $table->char('sha256_instantanea', 64);
            $table->unique(['id_solicitud', 'version_solicitud'], 'uq_laboratorios_decision_version');
        });
        Schema::create('laboratorios_comprobantes', function (Blueprint $table): void {
            $this->identifier($table, 'id_decision')->primary();
            $this->identifier($table, 'id_archivo');
            $table->char('sha256_archivo', 64);
            $table->unsignedInteger('tamano_archivo');
            $table->dateTime('emitido_en', 6);
            $table->foreign('id_decision')->references('id')->on('laboratorios_decisiones_solicitud')->restrictOnDelete();
        });
        Schema::create('laboratorios_notificaciones', function (Blueprint $table): void {
            $this->identifier($table, 'id')->primary();
            $this->identifier($table, 'id_identidad_destinataria');
            $this->identifier($table, 'id_solicitud');
            $this->identifier($table, 'id_decision')->unique();
            $table->string('estado_solicitud', 20);
            $table->dateTime('creado_en', 6);
            $table->index(['id_identidad_destinataria', 'id'], 'idx_laboratorios_notification_owner');
            $table->foreign('id_decision')->references('id')->on('laboratorios_decisiones_solicitud')->restrictOnDelete();
        });
    }

    private function identifier(Blueprint $table, string $name): ColumnDefinition
    {
        return $table->char($name, 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
    }

    public function down(): void
    {
        throw new RuntimeException('Laboratory decisions require roll-forward or verified recovery.');
    }
};
