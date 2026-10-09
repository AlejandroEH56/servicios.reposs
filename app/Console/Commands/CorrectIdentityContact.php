<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Shared\Infrastructure\Operations\OperationalAccess;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('iam:contact {identity} {--proof-file=}')]
#[Description('Correct contact with the existing Entra link and an audited operator proof file')]
class CorrectIdentityContact extends Command
{
    public function handle(OperationalAccess $access): int
    {
        $owner = $access->owner();
        try {
            $path = $this->option('proof-file');
            if (! is_string($path) || ! is_file($path) || filesize($path) > 8192) {
                throw new RuntimeException('CONTACT_PROOF_REQUIRED');
            }
            $proof = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            $identity = (string) $this->argument('identity');
            if (! is_array($proof) || ! Str::isUlid($identity)
                || ! Str::isUuid($proof['tenant'] ?? '') || ! Str::isUuid($proof['objectId'] ?? '')
                || ! array_key_exists('email', $proof)
                || ! in_array($proof['reason'] ?? null, ['USER_REQUEST', 'MIGRATION_RECONCILIATION'], true)
                || ($proof['email'] !== null && ! is_string($proof['email']))) {
                throw new RuntimeException('CONTACT_PROOF_INVALID');
            }
            $email = $proof['email'] === null ? null : mb_strtolower(trim($proof['email']));
            if ($email !== null && (strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
                throw new RuntimeException('CONTACT_EMAIL_INVALID');
            }
            DB::transaction(function () use ($identity, $proof, $email, $owner): void {
                $user = User::query()->lockForUpdate()->findOrFail($identity);
                $account = DB::table('iam_cuentas_externas')->where([
                    'id_identidad' => $identity, 'proveedor' => 'entra',
                    'id_inquilino' => strtolower($proof['tenant']), 'sujeto_proveedor' => strtolower($proof['objectId']),
                ])->lockForUpdate()->first();
                if (! $account || ($email !== null && User::query()->where('correo_normalizado', $email)->whereKeyNot($identity)->exists())) {
                    throw new RuntimeException('CONTACT_LINK_OR_COLLISION');
                }
                $user->correo_normalizado = $email;
                $user->version_autorizacion++;
                $user->save();
                DB::table('compartido_registros_auditoria')->insert([
                    'id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => null,
                    'nombre_contexto' => 'IAM', 'accion' => 'IDENTITY_CONTACT_CORRECTED',
                    'tipo_sujeto' => 'Identity', 'id_sujeto' => $identity, 'id_correlacion' => (string) Str::uuid(),
                    'metadatos' => json_encode(['operator' => $owner, 'reason' => $proof['reason'],
                        'authorizationVersion' => $user->version_autorizacion], JSON_THROW_ON_ERROR),
                ]);
            });
        } catch (Throwable) {
            $this->error('CONTACT_CORRECTION_REJECTED');

            return self::FAILURE;
        }
        $this->info('Contact corrected; link and state preserved, previous sessions revoked.');

        return self::SUCCESS;
    }
}
