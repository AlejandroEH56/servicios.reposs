<?php

namespace Tests\Integration;

use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PDOException;
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
        $password = bin2hex(random_bytes(32));
        $admin = DB::connection()->getPdo();
        $clientHost = $admin->quote(explode('@', (string) $admin->query('SELECT USER()')->fetchColumn(), 2)[1]);
        $runtime = $admin->quote($runtimeName).'@'.$clientHost;
        $migrator = $admin->quote($migratorName).'@'.$clientHost;
        try {
            $admin->exec('CREATE USER '.$runtime.' IDENTIFIED BY '.$admin->quote($password));
            $admin->exec('CREATE USER '.$migrator.' IDENTIFIED BY '.$admin->quote($password));
            $admin->exec('GRANT SELECT, INSERT ON servicios_moderno_test.compartido_registros_auditoria TO '.$runtime);
            $admin->exec('GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON servicios_moderno_test.* TO '.$migrator);
            $connection = config('database.connections.mysql');
            $dsn = "mysql:host={$connection['host']};port={$connection['port']};dbname=servicios_moderno_test;charset=utf8mb4";
            $limited = new PDO($dsn, $runtimeName, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $builder = new PDO($dsn, $migratorName, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $builder->exec('CREATE TABLE grants_probe (id INT PRIMARY KEY)');
            $id = (string) Str::ulid();
            $statement = $limited->prepare("INSERT INTO compartido_registros_auditoria (id,ocurrido_en,nombre_contexto,accion,tipo_sujeto,id_sujeto) VALUES (?,NOW(),'Test','GRANTS','Test',?)");
            $this->assertTrue($statement->execute([$id, $id]));
            foreach (['CREATE TABLE runtime_ddl_probe (id INT)', 'UPDATE compartido_registros_auditoria SET accion = accion', 'DELETE FROM compartido_registros_auditoria'] as $sql) {
                try {
                    $limited->exec($sql);
                    $this->fail('Runtime privilege unexpectedly granted.');
                } catch (PDOException $exception) {
                    $this->assertSame(1142, $exception->errorInfo[1]);
                }
            }
            $builder->exec('DROP TABLE grants_probe');
        } finally {
            $admin->exec('DROP USER IF EXISTS '.$runtime);
            $admin->exec('DROP USER IF EXISTS '.$migrator);
        }
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
        $tablesBefore = Schema::getTableListing();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        foreach ($tablesBefore as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasColumns('compartido_mensajes_salida', ['estado', 'bloqueado_por', 'bloqueado_hasta']));
        $this->assertFalse(Schema::hasTable('users'));
        $column = collect(Schema::getColumns('compartido_registros_auditoria'))->firstWhere('name', 'id');
        $this->assertSame('char', $column['type_name']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }
}
