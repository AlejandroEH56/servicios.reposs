<?php

namespace App\Modules\IAM\Infrastructure\Persistence;

use App\Modules\IAM\Application\AuthorizationAdministration;
use App\Modules\IAM\Application\AuthorizationDirectory;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthorizationStore implements AuthorizationAdministration, AuthorizationDirectory
{
    private function grants(string $identityId): Builder
    {
        return DB::table('iam_identidad_roles as assignment')
            ->join('iam_roles as role', 'role.id', '=', 'assignment.id_rol')
            ->join('iam_rol_permisos as grant', 'grant.id_rol', '=', 'role.id')
            ->join('iam_permisos as permission', 'permission.id', '=', 'grant.id_permiso')
            ->join('iam_identidades as identity', 'identity.id', '=', 'assignment.id_identidad')
            ->where('identity.id', $identityId)->where('identity.estado', 'ACTIVA')
            ->where(fn (Builder $query) => $query->whereNull('assignment.expira_en')->orWhere('assignment.expira_en', '>', now()));
    }

    public function effective(string $identityId): array
    {
        $rows = $this->grants($identityId)->whereIn('permission.codigo', array_keys(config('authorization.permissions')))
            ->select('permission.codigo', 'grant.alcance')->get();
        $roles = DB::table('iam_identidad_roles as assignment')->join('iam_roles as role', 'role.id', '=', 'assignment.id_rol')
            ->join('iam_identidades as identity', 'identity.id', '=', 'assignment.id_identidad')
            ->where('identity.id', $identityId)->where('identity.estado', 'ACTIVA')
            ->where(fn (Builder $query) => $query->whereNull('assignment.expira_en')->orWhere('assignment.expira_en', '>', now()))
            ->orderBy('role.codigo')->pluck('role.codigo')->all();

        return [
            'roles' => $roles,
            'permissions' => $rows->pluck('codigo')->unique()->sort()->values()->all(),
            'scopes' => $rows->map(fn (object $row) => $row->codigo.':'.strtolower($row->alcance))->unique()->sort()->values()->all(),
        ];
    }

    public function allows(string $identityId, string $permission, ?string $resourceOwner = null): bool
    {
        if (! array_key_exists($permission, config('authorization.permissions'))) {
            return false;
        }

        return $this->grants($identityId)->where('permission.codigo', $permission)
            ->where(function (Builder $query) use ($identityId, $resourceOwner): void {
                $query->where('grant.alcance', 'GLOBAL');
                if ($resourceOwner !== null && $resourceOwner === $identityId) {
                    $query->orWhere('grant.alcance', 'SELF');
                }
            })->exists();
    }

    public function roles(): array
    {
        return DB::table('iam_roles')->orderBy('codigo')->get()->map(function (object $role): array {
            return [
                'id' => $role->id, 'code' => $role->codigo, 'name' => $role->nombre, 'version' => $role->version,
                'permissions' => DB::table('iam_rol_permisos as grant')->join('iam_permisos as permission', 'permission.id', '=', 'grant.id_permiso')
                    ->where('grant.id_rol', $role->id)->orderBy('permission.codigo')
                    ->get(['permission.id as permissionId', 'permission.codigo as code', 'grant.alcance as scope'])->map(fn (object $row) => (array) $row)->all(),
            ];
        })->all();
    }

    public function permissions(): array
    {
        return DB::table('iam_permisos')->whereIn('codigo', array_keys(config('authorization.permissions')))
            ->orderBy('codigo')->get()->map(fn (object $row) => [
                'id' => $row->id, 'code' => $row->codigo, 'allowedScopes' => config('authorization.permissions')[$row->codigo],
            ])->all();
    }

    public function identities(string $search): array
    {
        return DB::table('iam_identidades')->when($search !== '', fn (Builder $query) => $query->where('nombre_mostrado', 'like', '%'.$search.'%'))
            ->orderBy('nombre_mostrado')->limit(100)->get(['id', 'nombre_mostrado', 'estado', 'version_autorizacion'])
            ->map(fn (object $row) => ['id' => $row->id, 'displayName' => $row->nombre_mostrado, 'status' => $row->estado, 'authorizationVersion' => $row->version_autorizacion])->all();
    }

    public function assignments(string $identityId): array
    {
        $identity = DB::table('iam_identidades')->where('id', $identityId)->first();
        abort_if($identity === null, 404);

        return [
            'identityId' => $identityId, 'authorizationVersion' => $identity->version_autorizacion,
            'roles' => DB::table('iam_identidad_roles as assignment')->join('iam_roles as role', 'role.id', '=', 'assignment.id_rol')
                ->where('assignment.id_identidad', $identityId)->orderBy('role.codigo')
                ->get(['role.id', 'role.codigo as code', 'assignment.expira_en as expiresAt'])->map(fn (object $row) => [
                    'id' => $row->id, 'code' => $row->code,
                    'expiresAt' => $row->expiresAt === null ? null : Carbon::parse($row->expiresAt)->toISOString(),
                ])->all(),
            'history' => DB::table('iam_historial_roles')->where('id_identidad', $identityId)->orderByDesc('ocurrido_en')->orderByDesc('id')->limit(100)
                ->get(['id', 'id_rol as roleId', 'accion as action', 'ocurrido_en as occurredAt', 'version_autorizacion as authorizationVersion'])
                ->map(fn (object $row) => [
                    'id' => $row->id, 'roleId' => $row->roleId, 'action' => $row->action,
                    'occurredAt' => Carbon::parse($row->occurredAt)->toISOString(), 'authorizationVersion' => $row->authorizationVersion,
                ])->all(),
        ];
    }

    private function lock(): void
    {
        DB::table('iam_controles')->where('codigo', 'RBAC')->lockForUpdate()->firstOrFail();
    }

    private function administrators(): int
    {
        return DB::table('iam_identidad_roles as assignment')->join('iam_rol_permisos as grant', 'grant.id_rol', '=', 'assignment.id_rol')
            ->join('iam_permisos as permission', 'permission.id', '=', 'grant.id_permiso')
            ->join('iam_identidades as identity', 'identity.id', '=', 'assignment.id_identidad')
            ->where('identity.estado', 'ACTIVA')->where('permission.codigo', 'iam.roles.gestionar')
            ->where('grant.alcance', 'GLOBAL')->whereNull('assignment.expira_en')->distinct()->count('identity.id');
    }

    private function authorizeActor(string $actor): void
    {
        abort_unless($this->allows($actor, 'iam.roles.gestionar'), 403);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $action, string $subject, string $type, ?string $actor, string $correlation, array $metadata = []): void
    {
        DB::table('compartido_registros_auditoria')->insert([
            'id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actor,
            'nombre_contexto' => 'IAM', 'accion' => $action, 'tipo_sujeto' => $type,
            'id_sujeto' => $subject, 'id_correlacion' => $correlation,
            'metadatos' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    public function createRole(array $data, string $actor, string $correlation): array
    {
        return DB::transaction(function () use ($data, $actor, $correlation): array {
            $this->lock();
            $this->authorizeActor($actor);
            $id = (string) Str::ulid();
            DB::table('iam_roles')->insert(['id' => $id, 'codigo' => $data['code'], 'nombre' => $data['name'],
                'version' => 1, 'creado_en' => now(), 'actualizado_en' => now()]);
            $this->audit('ROLE_CREATED', $id, 'Role', $actor, $correlation);

            return ['id' => $id, 'code' => $data['code'], 'name' => $data['name'], 'version' => 1, 'permissions' => []];
        }, 3);
    }

    public function replacePermissions(string $roleId, int $version, array $permissions, string $actor, string $correlation): array
    {
        return DB::transaction(function () use ($roleId, $version, $permissions, $actor, $correlation): array {
            $this->lock();
            $this->authorizeActor($actor);
            $role = DB::table('iam_roles')->where('id', $roleId)->lockForUpdate()->first();
            abort_if($role === null, 404);
            abort_if($role->version !== $version, 409);
            $catalog = config('authorization.permissions');
            foreach ($permissions as $permission) {
                $code = DB::table('iam_permisos')->where('id', $permission['permissionId'])->value('codigo');
                abort_unless(is_string($code) && in_array($permission['scope'], $catalog[$code] ?? [], true), 422);
            }
            $admins = $this->administrators();
            DB::table('iam_rol_permisos')->where('id_rol', $roleId)->delete();
            foreach ($permissions as $permission) {
                DB::table('iam_rol_permisos')->insert(['id_rol' => $roleId, 'id_permiso' => $permission['permissionId'],
                    'alcance' => $permission['scope'], 'otorgado_en' => now()]);
            }
            abort_if($admins > 0 && $this->administrators() === 0, 409);
            DB::table('iam_roles')->where('id', $roleId)->update(['version' => $version + 1, 'actualizado_en' => now()]);
            $identities = DB::table('iam_identidad_roles')->where('id_rol', $roleId)->pluck('id_identidad');
            DB::table('iam_identidades')->whereIn('id', $identities)->increment('version_autorizacion');
            $this->audit('ROLE_PERMISSIONS_CHANGED', $roleId, 'Role', $actor, $correlation, ['version' => $version + 1]);

            return collect($this->roles())->firstWhere('id', $roleId);
        }, 3);
    }

    public function changeAssignment(string $identityId, string $roleId, int $version, bool $grant, ?string $expiresAt, string $actor, string $correlation): void
    {
        DB::transaction(function () use ($identityId, $roleId, $version, $grant, $expiresAt, $actor, $correlation): void {
            $this->lock();
            $this->authorizeActor($actor);
            $this->applyAssignment($identityId, $roleId, $version, $grant, $expiresAt, $actor, $correlation);
        }, 3);
    }

    private function applyAssignment(string $identityId, string $roleId, int $version, bool $grant, ?string $expiresAt, ?string $actor, string $correlation): void
    {
        abort_unless(DB::table('iam_roles')->where('id', $roleId)->exists(), 404);
        $identity = DB::table('iam_identidades')->where('id', $identityId)->lockForUpdate()->first();
        abort_if($identity === null, 404);
        abort_if($identity->version_autorizacion !== $version, 409);
        abort_if($grant && $identity->estado !== 'ACTIVA', 409);
        $roleCanAdminister = DB::table('iam_rol_permisos as grant')->join('iam_permisos as permission', 'permission.id', '=', 'grant.id_permiso')
            ->where('grant.id_rol', $roleId)->where('permission.codigo', 'iam.roles.gestionar')->where('grant.alcance', 'GLOBAL')->exists();
        abort_if($grant && $roleCanAdminister && $expiresAt !== null, 422);
        $admins = $this->administrators();
        $expiresAt = $expiresAt === null ? null : Carbon::parse($expiresAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s.u');
        $key = ['id_identidad' => $identityId, 'id_rol' => $roleId];
        if ($grant) {
            DB::table('iam_identidad_roles')->updateOrInsert($key, ['id_identidad_otorgante' => $actor ?? $identityId, 'otorgado_en' => now(), 'expira_en' => $expiresAt]);
        } else {
            abort_unless(DB::table('iam_identidad_roles')->where($key)->exists(), 404);
            DB::table('iam_identidad_roles')->where($key)->delete();
        }
        abort_if($admins > 0 && $this->administrators() === 0, 409);
        DB::table('iam_identidades')->where('id', $identityId)->update(['version_autorizacion' => $version + 1, 'actualizado_en' => now()]);
        DB::table('iam_historial_roles')->insert(['id' => (string) Str::ulid(), 'id_identidad' => $identityId,
            'id_rol' => $roleId, 'accion' => $grant ? 'GRANTED' : 'REVOKED', 'ocurrido_en' => now(),
            'expira_en' => $expiresAt, 'version_autorizacion' => $version + 1]);
        $this->audit($grant ? 'ROLE_GRANTED' : 'ROLE_REVOKED', $identityId, 'Identity', $actor, $correlation, ['roleId' => $roleId, 'authorizationVersion' => $version + 1]);
    }

    public function bootstrap(string $identityId, string $operator): void
    {
        DB::transaction(function () use ($identityId, $operator): void {
            $this->lock();
            abort_if($this->administrators() > 0, 409);
            abort_unless(DB::table('iam_cuentas_externas')->where('id_identidad', $identityId)->where('proveedor', 'entra')->exists(), 422);
            foreach (['ADMIN_IAM', 'ADMIN_ORGANIZACION'] as $code) {
                $roleId = DB::table('iam_roles')->where('codigo', $code)->value('id');
                $version = DB::table('iam_identidades')->where('id', $identityId)->value('version_autorizacion');
                $this->applyAssignment($identityId, $roleId, $version, true, null, null, (string) Str::uuid());
            }
            $this->audit('ADMINISTRATION_BOOTSTRAPPED', $identityId, 'Identity', null, (string) Str::uuid(),
                ['operator' => $operator, 'matrixVersion' => config('authorization.matrix_version')]);
        }, 3);
    }
}
