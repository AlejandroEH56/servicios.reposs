<?php

namespace App\Modules\IAM\Infrastructure\Persistence;

use App\Modules\IAM\Application\IdentityDirectory;
use Illuminate\Support\Facades\DB;

class IdentityDirectoryStore implements IdentityDirectory
{
    public function search(string $search): array
    {
        return DB::table('iam_identidades')->where('estado', 'ACTIVA')
            ->when($search !== '', fn ($query) => $query->where('nombre_mostrado', 'like', '%'.$search.'%'))
            ->orderBy('nombre_mostrado')->limit(100)->get(['id', 'nombre_mostrado', 'estado'])
            ->map(fn (object $row) => ['id' => $row->id, 'displayName' => $row->nombre_mostrado, 'status' => $row->estado])->all();
    }

    public function find(string $identityId): ?array
    {
        $identity = DB::table('iam_identidades')->where('id', $identityId)->first();
        if ($identity === null) {
            return null;
        }
        $account = DB::table('iam_cuentas_externas')->where('id_identidad', $identityId)->where('proveedor', 'entra')->orderByDesc('actualizado_en')->first();
        $snapshot = $account && $account->instantanea_atributos ? json_decode($account->instantanea_atributos, true, flags: JSON_THROW_ON_ERROR) : [];

        return [
            'id' => $identity->id, 'displayName' => $identity->nombre_mostrado,
            'email' => $identity->correo_normalizado, 'status' => $identity->estado,
            'jobTitle' => $snapshot['jobTitle'] ?? null, 'profileVersion' => $snapshot['profileVersion'] ?? 0,
        ];
    }
}
