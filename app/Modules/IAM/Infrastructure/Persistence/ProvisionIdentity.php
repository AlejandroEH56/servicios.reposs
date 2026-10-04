<?php

namespace App\Modules\IAM\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\IAM\Application\IdentityProvisioner;
use App\Modules\IAM\Application\VerifiedIdentity;
use App\Modules\IAM\Domain\IdentityState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProvisionIdentity implements IdentityProvisioner
{
    public function provision(VerifiedIdentity $identity, string $correlationId): string
    {
        return DB::transaction(function () use ($identity, $correlationId): string {
            $key = ['proveedor' => 'entra', 'id_inquilino' => $identity->tenantId, 'sujeto_proveedor' => $identity->objectId];
            $account = DB::table('iam_cuentas_externas')->where($key)->lockForUpdate()->first();
            $new = $account === null;
            $user = $account ? User::query()->lockForUpdate()->findOrFail($account->id_identidad) : new User;
            if (! $new && in_array($user->estado, [IdentityState::Suspended, IdentityState::Deactivated], true)) {
                throw new RuntimeException('IAM_DISABLED');
            }
            $user->fill(['nombre_mostrado' => $identity->name, 'correo_normalizado' => $identity->email, 'estado' => IdentityState::Active]);
            $user->ultimo_acceso_en = now();
            $user->save();
            if ($new) {
                DB::table('iam_cuentas_externas')->insert($key + [
                    'id' => (string) Str::ulid(), 'id_identidad' => $user->id,
                    'nombre_principal' => $identity->principalName,
                    'creado_en' => now(), 'actualizado_en' => now(),
                ]);
                $eventId = (string) Str::ulid();
                DB::table('compartido_mensajes_salida')->insert([
                    'id' => $eventId, 'nombre_contexto' => 'IAM', 'tipo_agregado' => 'Identity',
                    'id_agregado' => $user->id, 'tipo_evento' => 'iam.identity-linked.v1',
                    'contenido' => json_encode([
                        'eventId' => $eventId, 'type' => 'iam.identity-linked.v1', 'version' => 1,
                        'aggregateId' => $user->id, 'aggregateVersion' => 1,
                        'occurredAt' => now()->toISOString(), 'actor' => $user->id, 'correlationId' => $correlationId,
                    ], JSON_THROW_ON_ERROR),
                    'ocurrido_en' => now(), 'disponible_en' => now(), 'estado' => 'PENDING',
                ]);
            } else {
                DB::table('iam_cuentas_externas')->where('id', $account->id)->update([
                    'nombre_principal' => $identity->principalName, 'actualizado_en' => now(),
                ]);
            }
            DB::table('compartido_registros_auditoria')->insert([
                'id' => (string) Str::ulid(), 'ocurrido_en' => now(),
                'id_identidad_actor' => $user->id, 'nombre_contexto' => 'IAM', 'accion' => 'LOGIN_GRANTED',
                'tipo_sujeto' => 'Identity', 'id_sujeto' => $user->id, 'id_correlacion' => $correlationId,
            ]);

            return $user->id;
        }, 3);
    }
}
