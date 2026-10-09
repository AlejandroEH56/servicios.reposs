<?php

namespace App\Console\Commands;

use Dotenv\Dotenv;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Throwable;

#[Signature('modernization:prepare-environments {--activate-runtime}')]
#[Description('Provision isolated local databases and persistent restricted runtime/migrator accounts')]
class PrepareEnvironments extends Command
{
    public function handle(): int
    {
        if (! app()->environment('local') || DB::getDriverName() !== 'mysql'
            || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true)
            || config('database.connections.mysql.url')) {
            $this->error('Preparation requires local MySQL, local environment and no DB_URL override.');

            return self::FAILURE;
        }
        $directory = base_path('.tools/environments');
        File::ensureDirectoryExists($directory, 0700);
        $adminFile = $directory.'/admin.env';
        if (! is_file($adminFile)) {
            File::put($adminFile, File::get(base_path('.env')));
            chmod($adminFile, 0600);
        }
        $original = File::get($adminFile);
        $adminValues = Dotenv::parse($original);
        $host = $adminValues['DB_HOST'] ?? '127.0.0.1';
        $port = $adminValues['DB_PORT'] ?? '3306';
        $current = $adminValues['DB_DATABASE'] ?? '';
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $current) || ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Unsafe database selection.');
        }
        try {
            $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $adminValues['DB_USERNAME'] ?? '', $adminValues['DB_PASSWORD'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $credentialsFile = $directory.'/database-credentials.json';
            $credentials = is_file($credentialsFile) ? json_decode(File::get($credentialsFile), true, flags: JSON_THROW_ON_ERROR) : [];
            $environments = ['local' => $current, 'staging' => 'servicios_moderno_stage', 'production' => 'servicios_moderno_prod'];
            foreach ($environments as $environment => $database) {
                $admin->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                foreach (['migrator', 'runtime'] as $role) {
                    $username = 'sr_'.match ($environment) {
                        'local' => 'dev', 'staging' => 'stage', default => 'prod'
                    }.'_'.$role;
                    $credentials[$username] ??= bin2hex(random_bytes(32));
                    File::put($credentialsFile, json_encode($credentials, JSON_THROW_ON_ERROR));
                    chmod($credentialsFile, 0600);
                    $account = $admin->quote($username)."@'localhost'";
                    $admin->exec('CREATE USER IF NOT EXISTS '.$account.' IDENTIFIED BY '.$admin->quote($credentials[$username]));
                    $admin->exec('ALTER USER '.$account.' IDENTIFIED BY '.$admin->quote($credentials[$username]));
                    $admin->exec('REVOKE ALL PRIVILEGES, GRANT OPTION FROM '.$account);
                    if ($role === 'migrator') {
                        $admin->exec('GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON `'.$database.'`.* TO '.$account);
                    } else {
                        foreach (['iam_identidades', 'iam_cuentas_externas', 'compartido_mensajes_salida', 'compartido_bandeja_entrada', 'compartido_archivos_almacenados', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
                            $admin->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON `'.$database.'`.`'.$table.'` TO '.$account);
                        }
                        $admin->exec('GRANT SELECT, INSERT ON `'.$database.'`.compartido_registros_auditoria TO '.$account);
                    }
                    $values = [
                        'APP_ENV' => $environment, 'APP_DEBUG' => $environment === 'local' ? 'true' : 'false',
                        'DB_DATABASE' => $database, 'DB_USERNAME' => $username, 'DB_PASSWORD' => $credentials[$username],
                        'DB_URL' => '', 'SESSION_COOKIE' => 'laravel_session', 'SESSION_DOMAIN' => 'null',
                        'SESSION_HTTP_ONLY' => 'true', 'SESSION_SAME_SITE' => 'lax',
                        'CACHE_PREFIX' => 'sr_'.$environment.'_', 'MODERNIZATION_OPERATIONS_ENABLED' => 'false',
                    ];
                    if ($environment !== 'local') {
                        $origin = 'https://localhost:'.($environment === 'staging' ? '8443' : '9443');
                        $values += ['APP_KEY' => $credentials[$environment.'_app_key'] ??= 'base64:'.base64_encode(random_bytes(32)),
                            'APP_URL' => $origin, 'MICROSOFT_REDIRECT_URI' => $origin.'/auth/microsoft/callback',
                            'SESSION_SECURE_COOKIE' => 'true', 'LOG_LEVEL' => 'info', 'PRIVATE_STORAGE_ROOT' => storage_path('app/'.$environment.'/private')];
                    }
                    $content = $this->environment($original, $values);
                    $file = $environment === 'local' ? ($role === 'runtime' ? $directory.'/runtime.env' : base_path('.env.migrator'))
                        : base_path('.env.'.$environment.($role === 'runtime' ? '' : '-migrator'));
                    File::put($file, $content);
                    chmod($file, 0600);
                    if ($role === 'migrator') {
                        config(['database.connections.provision' => array_replace(config('database.connections.mysql'), [
                            'database' => $database, 'username' => $username, 'password' => $credentials[$username], 'url' => null,
                        ])]);
                        DB::purge('provision');
                        if ($this->call('migrate', ['--database' => 'provision', '--force' => true]) !== self::SUCCESS) {
                            throw new RuntimeException('Environment migration failed.');
                        }
                        DB::disconnect('provision');
                    }
                }
            }
            File::put($credentialsFile, json_encode($credentials, JSON_THROW_ON_ERROR));
            $admin->exec('CREATE DATABASE IF NOT EXISTS servicios_moderno_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            File::put(base_path('.env.testing'), $this->environment($original, ['APP_ENV' => 'testing', 'DB_DATABASE' => 'servicios_moderno_test', 'DB_URL' => '']));
            $runtime = File::get($directory.'/runtime.env');
            File::put(base_path('.env.operator'), $this->environment($runtime, ['MODERNIZATION_OPERATIONS_ENABLED' => 'true', 'MODERNIZATION_OPERATIONS_OWNER' => 'AlejandroEH56']));
            if ($this->option('activate-runtime')) {
                File::put(base_path('.env'), $runtime);
            }
            $this->info('PASS: local/staging/production rehearsal provisioned; isolated test database ready. Credentials remain in ignored files.');
            $this->line('Protect generated files with scripts/protect-local-secrets.ps1 before using the environments.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('ENVIRONMENT_PROVISION_FAILED: inspect administrative access; credentials and SQL are not logged.');

            return self::FAILURE;
        }
    }

    /** @param array<string, string> $values */
    private function environment(string $content, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.'="'.str_replace(['\\', '"', '$', "\n", "\r"], ['\\\\', '\\"', '\\$', '', ''], $value).'"';
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            if (preg_match($pattern, $content)) {
                $content = preg_replace_callback($pattern, fn (): string => $line, $content);
            } else {
                $content .= "\n".$line."\n";
            }
        }

        return $content;
    }
}
