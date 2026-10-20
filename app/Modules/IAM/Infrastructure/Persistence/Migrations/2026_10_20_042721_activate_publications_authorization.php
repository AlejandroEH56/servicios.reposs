<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('iam_controles')->where('codigo', 'RBAC')->lockForUpdate()->first();
            DB::table('iam_permisos')->insertOrIgnore(['id' => (string) Str::ulid(), 'codigo' => 'publicaciones.administrar', 'descripcion' => 'Administrar publicaciones', 'creado_en' => now()]);
            $permission = DB::table('iam_permisos')->where('codigo', 'publicaciones.administrar')->value('id');
            foreach (['ADMIN_PUBLICACIONES', 'DRPSS_ADMIN'] as $code) {
                DB::table('iam_roles')->insertOrIgnore(['id' => (string) Str::ulid(), 'codigo' => $code, 'nombre' => $code,
                    'version' => 1, 'creado_en' => now(), 'actualizado_en' => now()]);
                $role = DB::table('iam_roles')->where('codigo', $code)->first();
                if (! DB::table('iam_rol_permisos')->where('id_rol', $role->id)->where('id_permiso', $permission)->exists()) {
                    DB::table('iam_rol_permisos')->insert(['id_rol' => $role->id, 'id_permiso' => $permission, 'alcance' => 'GLOBAL', 'otorgado_en' => now()]);
                    DB::table('iam_roles')->where('id', $role->id)->increment('version');
                    $affected = DB::table('iam_identidad_roles')->where('id_rol', $role->id)->pluck('id_identidad');
                    DB::table('iam_identidades')->whereIn('id', $affected)->increment('version_autorizacion');
                }
            }
            if (DB::table('iam_identidades')->exists()) {
                DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(),
                    'id_identidad_actor' => null, 'nombre_contexto' => 'IAM', 'accion' => 'PUBLICATIONS_MATRIX_ACTIVATED',
                    'tipo_sujeto' => 'Permission', 'id_sujeto' => $permission, 'id_correlacion' => (string) Str::uuid()]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Authorization changes require explicit audited roll-forward or verified recovery.');
    }
};
