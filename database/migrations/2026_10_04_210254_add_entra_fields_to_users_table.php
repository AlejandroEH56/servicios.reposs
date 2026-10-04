<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Compatibility marker for the unfinished users migration.
 * IAM uses iam_identidades + iam_cuentas_externas, never a second users table.
 */
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
