<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('iam_controles')->where('codigo', 'RBAC')->lockForUpdate()->firstOrFail();
            $permission = 'laboratorios.solicitudes.revisar';
            DB::table('iam_permisos')->insertOrIgnore(['id' => (string) Str::ulid(), 'codigo' => $permission, 'descripcion' => $permission, 'creado_en' => now()]);
            $permissionId = DB::table('iam_permisos')->where('codigo', $permission)->value('id');
            foreach (['ADMIN_LABORATORIOS' => 'GLOBAL', 'LABORATORISTA' => 'SELF'] as $code => $scope) {
                DB::table('iam_roles')->insertOrIgnore(['id' => (string) Str::ulid(), 'codigo' => $code, 'nombre' => $code, 'version' => 1, 'creado_en' => now(), 'actualizado_en' => now()]);
                $role = DB::table('iam_roles')->where('codigo', $code)->value('id');
                if (DB::table('iam_rol_permisos')->insertOrIgnore(['id_rol' => $role, 'id_permiso' => $permissionId, 'alcance' => $scope, 'otorgado_en' => now()])) {
                    DB::table('iam_roles')->where('id', $role)->increment('version');
                    DB::table('iam_identidades')->whereIn('id', DB::table('iam_identidad_roles')->where('id_rol', $role)->select('id_identidad'))->increment('version_autorizacion');
                    DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => null,
                        'nombre_contexto' => 'IAM', 'accion' => 'LABORATORY_REVIEW_MATRIX_ACTIVATED', 'tipo_sujeto' => 'Role',
                        'id_sujeto' => $role, 'id_correlacion' => (string) Str::uuid()]);
                }
            }
        }, 3);
    }

    public function down(): void
    {
        throw new RuntimeException('Authorization changes require audited roll-forward or verified recovery.');
    }
};
