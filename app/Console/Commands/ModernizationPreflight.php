<?php

namespace App\Console\Commands;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

#[Signature('modernization:preflight {--oidc : Check public tenant metadata over verified TLS without login}')]
#[Description('Read-only modernization checks; prints no credentials or identity data')]
class ModernizationPreflight extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checks = ['environment' => app()->environment(), 'php' => PHP_VERSION];
        foreach (['tenant_id', 'client_id', 'client_secret', 'redirect_uri', 'atlos_user_group_id'] as $key) {
            $checks['entra_'.$key.'_configured'] = filled(config('services.microsoft.'.$key));
        }
        $checks['callback_tls'] = parse_url((string) config('services.microsoft.redirect_uri'), PHP_URL_SCHEME) === 'https';
        try {
            $checks['database_reachable'] = DB::select('SELECT 1') !== [];
            $checks['database_version'] = DB::getDriverName() === 'mysql' ? DB::selectOne('SELECT VERSION() AS version')->version : DB::getDriverName();
            foreach (['iam_identidades', 'iam_cuentas_externas', 'compartido_registros_auditoria', 'compartido_mensajes_salida', 'compartido_bandeja_entrada', 'sessions', 'cache'] as $table) {
                $checks['table_'.$table] = Schema::hasTable($table);
            }
            $checks['outbox_lease_schema'] = Schema::hasColumns('compartido_mensajes_salida', ['estado', 'disponible_en', 'bloqueado_hasta', 'bloqueado_por', 'fallido_en']);
            $checks['legacy_state_count'] = Schema::hasTable('iam_identidades') ? DB::table('iam_identidades')->whereNotIn('estado', ['PENDIENTE', 'ACTIVA', 'SUSPENDIDA', 'DESACTIVADA'])->count() : 0;
            foreach (['iam_identidades', 'compartido_registros_auditoria', 'compartido_mensajes_salida'] as $table) {
                $checks['rows_'.$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
            }
        } catch (Throwable) {
            $checks['database_reachable'] = false;
        }
        if ($this->option('oidc')) {
            try {
                $tenant = config('services.microsoft.tenant_id');
                if (! Str::isUuid($tenant)) {
                    throw new \RuntimeException('OIDC_CONFIGURATION_INVALID');
                }
                $issuer = "https://login.microsoftonline.com/{$tenant}/v2.0";
                $metadata = Http::withoutRedirecting()->connectTimeout(3)->timeout(10)->get($issuer.'/.well-known/openid-configuration')->throw()->json();
                $checks['oidc_metadata_tls_verified'] = ($metadata['issuer'] ?? '') === $issuer
                    && parse_url($metadata['jwks_uri'] ?? '', PHP_URL_HOST) === 'login.microsoftonline.com'
                    && parse_url($metadata['jwks_uri'] ?? '', PHP_URL_SCHEME) === 'https';
            } catch (Throwable $exception) {
                $checks['oidc_metadata_tls_verified'] = false;
                $previous = $exception->getPrevious();
                $checks['oidc_metadata_failure_kind'] = class_basename($exception);
                $checks['oidc_metadata_curl_errno'] = $previous instanceof RequestException
                    ? ($previous->getHandlerContext()['errno'] ?? null) : null;
            }
        }
        $this->line(json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $required = array_filter($checks, fn ($key) => str_ends_with($key, '_configured') || str_starts_with($key, 'table_'), ARRAY_FILTER_USE_KEY);

        return (! in_array(false, $required, true) && $checks['database_reachable'] && ($checks['outbox_lease_schema'] ?? false)
            && ($checks['legacy_state_count'] ?? 1) === 0 && ($checks['oidc_metadata_tls_verified'] ?? ! $this->option('oidc'))) ? self::SUCCESS : self::FAILURE;
    }
}
