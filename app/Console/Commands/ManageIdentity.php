<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\IAM\Domain\IdentityState;
use App\Modules\Shared\Infrastructure\Operations\OperationalAccess;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('iam:state {identity} {state} {--reason=}')]
#[Description('Audited identity transition from the restricted operator environment')]
class ManageIdentity extends Command
{
    public function handle(OperationalAccess $access): int
    {
        $owner = $access->owner();
        $state = IdentityState::tryFrom((string) $this->argument('state'));
        $reason = $this->option('reason');
        if (! Str::isUlid((string) $this->argument('identity')) || ! $state
            || ! in_array($reason, ['SECURITY_REVIEW', 'USER_REQUEST', 'REHABILITATION', 'MIGRATION_RECONCILIATION'], true)) {
            $this->error('Invalid identity, state or reason code.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($owner, $state, $reason): int {
            $user = User::query()->lockForUpdate()->findOrFail($this->argument('identity'));
            $allowed = match ($user->estado) {
                IdentityState::Active => [IdentityState::Suspended, IdentityState::Deactivated],
                IdentityState::Pending => [IdentityState::Deactivated],
                IdentityState::Suspended => [IdentityState::Pending, IdentityState::Deactivated],
                IdentityState::Deactivated => [IdentityState::Pending],
            };
            if (! in_array($state, $allowed, true)) {
                $this->error('Transition denied; activation requires fresh authorized group proof.');

                return self::FAILURE;
            }
            $previous = $user->estado;
            $user->estado = $state;
            $user->version_autorizacion++;
            $user->deshabilitado_en = $state === IdentityState::Pending ? null : now();
            $user->save();
            DB::table('compartido_registros_auditoria')->insert([
                'id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => null,
                'nombre_contexto' => 'IAM', 'accion' => 'IDENTITY_STATE_CHANGED',
                'tipo_sujeto' => 'Identity', 'id_sujeto' => $user->id, 'id_correlacion' => (string) Str::uuid(),
                'metadatos' => json_encode(['operator' => $owner, 'reason' => $reason, 'from' => $previous->value,
                    'to' => $state->value, 'authorizationVersion' => $user->version_autorizacion], JSON_THROW_ON_ERROR),
            ]);
            $this->info('Identity transition applied; previous sessions are revoked.');

            return self::SUCCESS;
        });
    }
}
