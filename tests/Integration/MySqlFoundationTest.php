<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\IAM\Application\AuthorizationAdministration;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MySqlFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('servicios_moderno_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_identifier_boundary_ignores_tables_from_another_database(): void
    {
        $otherDatabase = 'identifier_probe_'.bin2hex(random_bytes(6));
        DB::statement('CREATE DATABASE `'.$otherDatabase.'`');
        try {
            DB::statement('CREATE TABLE `'.$otherDatabase.'`.`unexpected_domain` (id BIGINT PRIMARY KEY)');
            $this->artisan('modernization:verify-identifiers')->assertSuccessful();
        } finally {
            DB::statement('DROP DATABASE `'.$otherDatabase.'`');
        }
    }

    public function test_two_connections_skip_locked_rows_and_never_claim_same_event(): void
    {
        foreach ([1, 2] as $index) {
            $id = (string) Str::ulid();
            DB::table('compartido_mensajes_salida')->insert(['id' => $id, 'nombre_contexto' => 'Test', 'tipo_agregado' => 'Test', 'id_agregado' => $id,
                'tipo_evento' => 'test.v1', 'contenido' => '{}', 'estado' => 'PENDING', 'ocurrido_en' => now()->addSeconds($index), 'disponible_en' => now()]);
        }
        config(['database.connections.worker2' => config('database.connections.mysql')]);
        DB::purge('worker2');
        $primary = DB::connection();
        $primary->beginTransaction();
        try {
            $firstWorker = (new OutboxProcessor)->claim(1);
            $this->assertCount(1, $firstWorker);
            $other = (new OutboxProcessor(connection: 'worker2'))->claim();
            $this->assertCount(1, $other);
            $this->assertNotSame($firstWorker[0]->id, $other[0]->id);
        } finally {
            $primary->rollBack();
            DB::disconnect('worker2');
        }
    }

    public function test_runtime_cannot_ddl_or_modify_audit_but_migrator_can_ddl(): void
    {
        $runtimeName = 'mr_'.bin2hex(random_bytes(6));
        $migratorName = 'mm_'.bin2hex(random_bytes(6));
        $operatorName = 'mo_'.bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(32));
        $admin = DB::connection()->getPdo();
        $clientHost = $admin->quote(explode('@', (string) $admin->query('SELECT USER()')->fetchColumn(), 2)[1]);
        $runtime = $admin->quote($runtimeName).'@'.$clientHost;
        $migrator = $admin->quote($migratorName).'@'.$clientHost;
        $operator = $admin->quote($operatorName).'@'.$clientHost;
        try {
            $admin->exec('CREATE USER '.$runtime.' IDENTIFIED BY '.$admin->quote($password));
            $admin->exec('CREATE USER '.$migrator.' IDENTIFIED BY '.$admin->quote($password));
            $admin->exec('CREATE USER '.$operator.' IDENTIFIED BY '.$admin->quote($password));
            $privileges = json_decode(file_get_contents(database_path('privileges.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach ($privileges['runtimeWritable'] as $table) {
                $admin->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON servicios_moderno_test.`'.$table.'` TO '.$runtime);
            }
            foreach ($privileges['appendOnly'] as $table) {
                $admin->exec('GRANT SELECT, INSERT ON servicios_moderno_test.`'.$table.'` TO '.$runtime);
                $admin->exec('GRANT SELECT, INSERT ON servicios_moderno_test.`'.$table.'` TO '.$operator);
            }
            foreach ($privileges['operatorWritable'] as $table) {
                $admin->exec('GRANT SELECT, INSERT, UPDATE ON servicios_moderno_test.`'.$table.'` TO '.$operator);
            }
            foreach ($privileges['operatorReadOnly'] as $table) {
                $admin->exec('GRANT SELECT ON servicios_moderno_test.`'.$table.'` TO '.$operator);
            }
            $admin->exec('GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON servicios_moderno_test.* TO '.$migrator);
            $connection = config('database.connections.mysql');
            $dsn = "mysql:host={$connection['host']};port={$connection['port']};dbname=servicios_moderno_test;charset=utf8mb4";
            $limited = new PDO($dsn, $runtimeName, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $builder = new PDO($dsn, $migratorName, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $operatorConnection = new PDO($dsn, $operatorName, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $operatorConnection->beginTransaction();
            $this->assertNotFalse($operatorConnection->query("SELECT id FROM iam_controles WHERE codigo = 'RBAC' FOR UPDATE")->fetchColumn());
            $operatorConnection->rollBack();
            $builder->exec('CREATE TABLE grants_probe (id INT PRIMARY KEY)');
            $id = (string) Str::ulid();
            $statement = $limited->prepare("INSERT INTO compartido_registros_auditoria (id,ocurrido_en,nombre_contexto,accion,tipo_sujeto,id_sujeto) VALUES (?,NOW(),'Test','GRANTS','Test',?)");
            $this->assertTrue($statement->execute([$id, $id]));
            foreach (['CREATE TABLE runtime_ddl_probe (id INT)', 'UPDATE compartido_registros_auditoria SET accion = accion', 'DELETE FROM compartido_registros_auditoria', 'UPDATE iam_historial_roles SET accion = accion', 'DELETE FROM iam_historial_roles'] as $sql) {
                try {
                    $limited->exec($sql);
                    $this->fail('Runtime privilege unexpectedly granted.');
                } catch (PDOException $exception) {
                    $this->assertSame(1142, $exception->errorInfo[1]);
                }
            }
            foreach (['CREATE TABLE operator_ddl_probe (id INT)', 'UPDATE iam_historial_roles SET accion = accion', 'DELETE FROM iam_historial_roles', 'UPDATE compartido_registros_auditoria SET accion = accion', 'DELETE FROM compartido_registros_auditoria'] as $sql) {
                try {
                    $operatorConnection->exec($sql);
                    $this->fail('Operator append-only or DDL boundary bypassed.');
                } catch (PDOException $exception) {
                    $this->assertSame(1142, $exception->errorInfo[1]);
                }
            }
            $builder->exec('DROP TABLE grants_probe');
        } finally {
            $admin->exec('DROP USER IF EXISTS '.$runtime);
            $admin->exec('DROP USER IF EXISTS '.$migrator);
            $admin->exec('DROP USER IF EXISTS '.$operator);
        }
    }

    public function test_rbac_and_organization_writes_wait_for_their_mysql_mutex_and_reject_stale_versions(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        DB::table('iam_cuentas_externas')->insert(['id' => (string) Str::ulid(), 'id_identidad' => $actor->id,
            'proveedor' => 'entra', 'id_inquilino' => (string) Str::uuid(), 'sujeto_proveedor' => (string) Str::uuid(),
            'creado_en' => now(), 'actualizado_en' => now()]);
        $administration = app(AuthorizationAdministration::class);
        $administration->bootstrap($actor->id, 'CONCURRENCY_TEST');
        $role = DB::table('iam_roles')->where('codigo', 'EMPLEADO')->value('id');
        config(['database.connections.contender' => config('database.connections.mysql')]);
        DB::purge('contender');
        DB::connection('contender')->statement('SET SESSION innodb_lock_wait_timeout = 1');
        foreach (['iam_controles' => 'RBAC', 'organizacion_controles' => 'TREE'] as $table => $code) {
            $primary = DB::connection('mysql');
            $primary->beginTransaction();
            try {
                $primary->table($table)->where('codigo', $code)->lockForUpdate()->first();
                DB::setDefaultConnection('contender');
                try {
                    if ($code === 'RBAC') {
                        $administration->changeAssignment($target->id, $role, 1, true, null, $actor->id, (string) Str::uuid());
                    } else {
                        app(OrganizationDirectory::class)->execute('createUnit', null,
                            ['code' => 'CONTENDER', 'name' => 'Concurrency fixture', 'kind' => 'DEPARTAMENTO', 'active' => true], $actor->id, (string) Str::uuid());
                    }
                    $this->fail('Concurrent operation bypassed its aggregate mutex.');
                } catch (QueryException $exception) {
                    $this->assertSame(1205, $exception->errorInfo[1] ?? null, $exception->getMessage());
                }
            } finally {
                DB::setDefaultConnection('mysql');
                $primary->rollBack();
            }
        }
        DB::disconnect('contender');
        $this->assertDatabaseMissing('iam_identidad_roles', ['id_identidad' => $target->id]);
        $this->assertDatabaseMissing('organizacion_unidades', ['codigo' => 'CONTENDER']);
        $administration->changeAssignment($target->id, $role, 1, true, null, $actor->id, (string) Str::uuid());
        $this->assertSame(2, $administration->assignments($target->id)['authorizationVersion']);
        try {
            $administration->changeAssignment($target->id, $role, 1, false, null, $actor->id, (string) Str::uuid());
            $this->fail('Stale concurrent version accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(1, DB::table('iam_historial_roles')->where('id_identidad', $target->id)->count());
    }

    public function test_baseline_adoption_upgrade_preserves_business_table_and_unique_email(): void
    {
        $this->artisan('db:wipe', ['--force' => true])->assertSuccessful();
        $sql = file_get_contents(database_path('mysql/001_create_servicios_moderno.sql'));
        $replacements = 0;
        $sql = preg_replace('/CREATE DATABASE `servicios_moderno`.*?;\s*USE `servicios_moderno`;/s', '', $sql, 1, $replacements);
        $this->assertSame(1, $replacements, 'Baseline database selection must be removed before adoption.');
        $this->assertDoesNotMatchRegularExpression('/^\s*(?:(?:CREATE|DROP|ALTER)\s+(?:DATABASE|SCHEMA)|USE\s+)/im', $sql);
        $this->assertSame('servicios_moderno_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        DB::unprepared($sql);
        $employee = (string) Str::ulid();
        $degree = (string) Str::ulid();
        DB::table('organizacion_empleados')->insert(['id' => $employee, 'nombres' => 'Catalog fixture', 'primer_apellido' => 'Conversion']);
        DB::table('organizacion_niveles_academicos')->insert(['id' => 7, 'codigo' => 'CONVERSION_FIXTURE', 'nombre' => 'Nivel de ensayo', 'orden_jerarquia' => 7]);
        DB::table('organizacion_grados_academicos')->insert(['id' => $degree, 'id_empleado' => $employee, 'id_nivel_academico' => 7, 'nombre_programa' => 'Programa de ensayo']);
        $tablesBefore = Schema::getTableListing();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        foreach ($tablesBefore as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasColumns('compartido_mensajes_salida', ['estado', 'bloqueado_por', 'bloqueado_hasta']));
        $this->assertFalse(Schema::hasTable('users'));
        $this->artisan('modernization:verify-identifiers')->assertSuccessful();
        $this->artisan('modernization:verify-identifiers', ['--enable-module' => ['organization']])->assertSuccessful();
        $level = DB::table('organizacion_niveles_academicos')->where('codigo', 'CONVERSION_FIXTURE')->first();
        $this->assertTrue(Str::isUlid($level->id));
        $this->assertDatabaseHas('organizacion_grados_academicos', ['id' => $degree, 'id_nivel_academico' => $level->id]);
        $this->assertDatabaseHas('migracion_mapa_ids', ['tabla_origen' => 'organizacion_niveles_academicos', 'id_legacy' => '7', 'id_destino' => $level->id]);
        $column = collect(Schema::getColumns('compartido_registros_auditoria'))->firstWhere('name', 'id');
        $this->assertSame('char', $column['type_name']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }
}
