<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use PDO;
use PDOException;
use Tests\TestCase;

class PersistentEnvironmentTest extends TestCase
{
    public function test_real_local_and_staging_accounts_have_restricted_grants(): void
    {
        if (! is_file(base_path('.env.staging')) || ! is_file(base_path('.tools/environments/runtime.env'))) {
            $this->markTestSkipped('Provision local environments to verify their persistent accounts.');
        }
        foreach (['.tools/environments/runtime.env', '.env.staging', '.env.production'] as $file) {
            $values = Dotenv::parse(file_get_contents(base_path($file)));
            $pdo = new PDO('mysql:host='.$values['DB_HOST'].';port='.($values['DB_PORT'] ?? '3306').';dbname='.$values['DB_DATABASE'].';charset=utf8mb4',
                $values['DB_USERNAME'], $values['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->assertSame($values['DB_DATABASE'], $pdo->query('SELECT DATABASE()')->fetchColumn());
            $this->assertNotFalse($pdo->query('SELECT COUNT(*) FROM iam_identidades')->fetchColumn());
            foreach (['CREATE TABLE runtime_denied_probe (id INT)', 'UPDATE compartido_registros_auditoria SET accion = accion',
                'DELETE FROM compartido_registros_auditoria', 'SELECT User FROM mysql.user'] as $sql) {
                try {
                    $pdo->exec($sql);
                    $this->fail('Restricted principal unexpectedly allowed a privileged operation.');
                } catch (PDOException $exception) {
                    $this->assertContains($exception->errorInfo[1], [1142, 1044]);
                }
            }
        }
    }

    public function test_staging_migrator_can_ddl_and_cannot_manage_global_accounts(): void
    {
        if (! is_file(base_path('.env.staging-migrator'))) {
            $this->markTestSkipped('Provision local environments to verify their persistent accounts.');
        }
        $values = Dotenv::parse(file_get_contents(base_path('.env.staging-migrator')));
        $pdo = new PDO('mysql:host='.$values['DB_HOST'].';port='.($values['DB_PORT'] ?? '3306').';dbname='.$values['DB_DATABASE'],
            $values['DB_USERNAME'], $values['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertNotFalse($pdo->exec('CREATE TABLE IF NOT EXISTS migrator_ddl_probe (id INT PRIMARY KEY)'));
        $this->assertNotFalse($pdo->exec('DROP TABLE migrator_ddl_probe'));
        try {
            $pdo->exec('CREATE USER grants_denied_probe');
            $this->fail('Migrator cannot create global users.');
        } catch (PDOException $exception) {
            $this->assertSame(1227, $exception->errorInfo[1]);
        }
    }
}
