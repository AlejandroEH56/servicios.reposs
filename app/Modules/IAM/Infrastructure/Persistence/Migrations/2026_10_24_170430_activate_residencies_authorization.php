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
            foreach (['RESIDENTE', 'DRPSS_REVISOR', 'DRPSS_ADMIN', 'ADMIN_RESIDENCIAS'] as $code) {
                DB::table('iam_roles')->insertOrIgnore(['id' => (string) Str::ulid(), 'codigo' => $code, 'nombre' => $code, 'version' => 1, 'creado_en' => now(), 'actualizado_en' => now()]);
                $role = DB::table('iam_roles')->where('codigo', $code)->firstOrFail();
                $changed = false;
                foreach (config('authorization.roles')[$code] as $permissionCode => $scope) {
                    if (! str_starts_with($permissionCode, 'residencies.')) {
                        continue;
                    }
                    DB::table('iam_permisos')->insertOrIgnore(['id' => (string) Str::ulid(), 'codigo' => $permissionCode, 'descripcion' => $permissionCode, 'creado_en' => now()]);
                    $permission = DB::table('iam_permisos')->where('codigo', $permissionCode)->value('id');
                    $grant = DB::table('iam_rol_permisos')->where('id_rol', $role->id)->where('id_permiso', $permission);
                    $existing = $grant->first();
                    if ($existing === null) {
                        $grant->insert(['id_rol' => $role->id, 'id_permiso' => $permission, 'alcance' => $scope, 'otorgado_en' => now()]);
                        $changed = true;
                    } elseif ($existing->alcance !== $scope) {
                        $grant->update(['alcance' => $scope, 'otorgado_en' => now()]);
                        $changed = true;
                    }
                }
                if ($changed) {
                    DB::table('iam_roles')->where('id', $role->id)->increment('version');
                    DB::table('iam_identidades')->whereIn('id', DB::table('iam_identidad_roles')->where('id_rol', $role->id)->pluck('id_identidad'))->increment('version_autorizacion');
                }
                if (DB::table('iam_identidades')->exists()) {
                    DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => null, 'nombre_contexto' => 'IAM', 'accion' => 'RESIDENCIES_MATRIX_ACTIVATED', 'tipo_sujeto' => 'Role', 'id_sujeto' => $role->id, 'id_correlacion' => (string) Str::uuid()]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Authorization requires audited roll-forward or verified recovery.');
    }
};
