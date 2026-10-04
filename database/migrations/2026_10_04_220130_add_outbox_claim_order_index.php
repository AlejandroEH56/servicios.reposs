<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasIndex('compartido_mensajes_salida', 'idx_outbox_order')) {
            Schema::table('compartido_mensajes_salida', function (Blueprint $table): void {
                $table->index(['ocurrido_en', 'id'], 'idx_outbox_order');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('compartido_mensajes_salida', function (Blueprint $table): void {
            $table->dropIndex('idx_outbox_order');
        });
    }
};
