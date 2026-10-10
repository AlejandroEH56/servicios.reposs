<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

#[Signature('modernization:verify-identifiers {--enable-module=* : Require converted catalogs before enabling a domain module}')]
#[Description('Read-only identifier boundary check for active domains and approved technical exceptions')]
class VerifyIdentifiers extends Command
{
    private const TECHNICAL = ['migrations', 'jobs', 'failed_jobs', 'job_batches', 'sessions', 'migracion_mapa_ids'];

    private const CATALOGS = [
        'organizacion_niveles_academicos' => 'organization',
        'residencias_modalidades' => 'residencies',
        'residencias_sectores' => 'residencies',
        'residencias_ramos' => 'residencies',
        'planificacion_tipos_dia_inhabil' => 'planning',
        'inventario_detalles_corte_mensual' => 'inventory',
    ];

    public function handle(): int
    {
        $enabled = $this->option('enable-module');
        $prefixes = ['organization' => ['organization', 'organizacion'], 'residencies' => ['residencies', 'residencias'],
            'planning' => ['planning', 'planificacion'], 'inventory' => ['inventory', 'inventario']];
        foreach ($enabled as $module) {
            if (! array_key_exists($module, $prefixes)) {
                $this->error('Unknown domain module.');

                return self::FAILURE;
            }
        }
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($prefixes as $module => $names) {
                foreach ($names as $name) {
                    if (preg_match('#^api/v[0-9]+/'.$name.'(?:/|$)#', $route->uri())) {
                        $enabled[] = $module;
                    }
                }
            }
        }
        $failures = [];
        $domainColumns = 0;
        $exceptions = [];
        $tables = Schema::getTables(DB::getDriverName() === 'mysql' ? DB::connection()->getDatabaseName() : null);
        foreach ($tables as $table) {
            $name = $table['name'];
            if (in_array($name, self::TECHNICAL, true) || str_starts_with($name, 'sqlite_')) {
                continue;
            }
            $columns = collect(Schema::getColumns($name))->keyBy('name');
            foreach ($columns as $column) {
                if ($column['name'] !== 'id') {
                    continue;
                }
                $isUlid = in_array($column['type_name'], ['char', 'varchar'], true)
                    && ! $column['auto_increment']
                    && (DB::getDriverName() !== 'mysql' || ($column['type'] === 'char(26)' && $column['collation'] === 'ascii_bin'));
                if ($isUlid) {
                    $domainColumns++;
                } elseif (isset(self::CATALOGS[$name]) && in_array($column['type_name'], ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true) && ! in_array(self::CATALOGS[$name], $enabled, true)) {
                    $exceptions[] = $name;
                } else {
                    $failures[] = $name.'.id requires ULID before enabling domain writes';
                }
            }
        }
        foreach ($tables as $table) {
            $columns = collect(Schema::getColumns($table['name']))->keyBy('name');
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                $module = self::CATALOGS[$foreignKey['foreign_table']] ?? null;
                if ($module === null || ! in_array($module, $enabled, true)) {
                    continue;
                }
                foreach ($foreignKey['columns'] as $name) {
                    $column = $columns[$name];
                    if (! in_array($column['type_name'], ['char', 'varchar'], true)
                        || (DB::getDriverName() === 'mysql' && ($column['type'] !== 'char(26)' || $column['collation'] !== 'ascii_bin'))) {
                        $failures[] = $table['name'].'.'.$name.' references an enabled catalog without ULID conversion';
                    }
                }
            }
        }
        foreach (self::CATALOGS as $catalog => $module) {
            if (in_array($module, $enabled, true) && ! Schema::hasTable($catalog)) {
                $failures[] = $catalog.' conversion missing for enabled module';
            }
        }
        foreach ($failures as $failure) {
            $this->error($failure);
        }
        $this->line(json_encode(['domainUlidColumns' => $domainColumns, 'phaseExceptions' => $exceptions,
            'enabledModules' => array_values(array_unique($enabled)), 'violations' => count($failures)], JSON_THROW_ON_ERROR));

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
