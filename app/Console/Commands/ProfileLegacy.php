<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

#[Signature('modernization:profile-legacy {--source=C:/GitHubRepositories/servicios.proyecto}')]
#[Description('Read-only anonymized schema and quality profile of the authorized local legacy')]
class ProfileLegacy extends Command
{
    public function handle(): int
    {
        if (! app()->environment('local', 'testing') || DB::getDriverName() !== 'mysql'
            || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true)) {
            $this->error('Legacy profiling requires the authorized local MySQL administrator environment.');

            return self::FAILURE;
        }
        $source = realpath((string) $this->option('source'));
        if (! $source || ! is_file($source.'/app/Config/Database.php')) {
            $this->error('Legacy configuration not found.');

            return self::FAILURE;
        }
        $configuration = File::get($source.'/app/Config/Database.php');
        preg_match_all('/public array \\$(residentes|compartida|laboratorios|inventarios)\\s*=\\s*\\[(.*?)\\n\\s*\\];/s', $configuration, $groups, PREG_SET_ORDER);
        $report = ['executedAt' => now()->toISOString(), 'source' => $source,
            'sourceConfigSha256' => hash('sha256', $configuration), 'databaseVersion' => DB::selectOne('SELECT VERSION() AS version')->version,
            'mode' => 'READ_ONLY_ANONYMIZED', 'schemas' => []];
        $directory = base_path('artifacts/legacy-profile');
        File::ensureDirectoryExists($directory);
        $complete = true;
        foreach ($groups as $group) {
            preg_match("/'database'\\s*=>\\s*'([^']*)'/", $group[2], $name);
            $schema = $name[1] ?? '';
            if (! preg_match('/^[A-Za-z0-9_]+$/', $schema)) {
                continue;
            }
            $tables = DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME', [$schema, 'BASE TABLE']);
            if ($tables === []) {
                $report['schemas'][$group[1]] = ['name' => $schema, 'status' => 'ABSENT_OR_EMPTY', 'tables' => []];
                $complete = false;

                continue;
            }
            $profile = ['name' => $schema, 'status' => 'PROFILED', 'tables' => []];
            $ddl = '';
            try {
                foreach ($tables as $row) {
                    $table = $row->TABLE_NAME;
                    $qualified = $this->identifier($schema).'.'.$this->identifier($table);
                    $create = (array) DB::selectOne('SHOW CREATE TABLE '.$qualified);
                    $ddl .= preg_replace('/ AUTO_INCREMENT=\\d+/', '', (string) array_values($create)[1]).";\n\n";
                    $entry = ['count' => DB::selectOne('SELECT COUNT(*) AS total FROM '.$qualified)->total,
                        'columns' => DB::select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$schema, $table]),
                        'emailQuality' => []];
                    foreach ($entry['columns'] as $column) {
                        if (! preg_match('/^(email|correo|correo_electronico|correo_normalizado|principal_name)$/i', $column->COLUMN_NAME)) {
                            continue;
                        }
                        $field = $this->identifier($column->COLUMN_NAME);
                        $entry['emailQuality'][$column->COLUMN_NAME] = [
                            'nullOrBlank' => DB::selectOne('SELECT COUNT(*) AS total FROM '.$qualified.' WHERE '.$field.' IS NULL OR TRIM('.$field.") = ''")->total,
                            'duplicateGroups' => DB::selectOne('SELECT COUNT(*) AS total FROM (SELECT LOWER(TRIM('.$field.')) FROM '.$qualified.' WHERE '.$field.' IS NOT NULL AND TRIM('.$field.") <> '' GROUP BY LOWER(TRIM(".$field.')) HAVING COUNT(*) > 1) duplicates')->total,
                        ];
                    }
                    $profile['tables'][$table] = $entry;
                }
                $file = $directory.'/'.$schema.'-schema.sql';
                File::put($file, $ddl);
                $profile['ddlArtifact'] = 'artifacts/legacy-profile/'.$schema.'-schema.sql';
                $profile['ddlSha256'] = hash('sha256', $ddl);
            } catch (Throwable) {
                $profile['status'] = 'ACCESS_DENIED_OR_PROFILE_FAILED';
                $complete = false;
            }
            $report['schemas'][$group[1]] = $profile;
        }
        $report['complete'] = $complete && count($report['schemas']) === 4;
        File::put($directory.'/manifest.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->line('Read-only profile: artifacts/legacy-profile/manifest.json');
        $this->line($report['complete'] ? 'PASS: four legacy schemas profiled.' : 'PARTIAL: one or more physical legacy schemas are absent, empty or inaccessible.');

        return $report['complete'] ? self::SUCCESS : self::FAILURE;
    }

    private function identifier(string $value): string
    {
        return '`'.str_replace('`', '``', $value).'`';
    }
}
