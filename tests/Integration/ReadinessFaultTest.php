<?php

namespace Tests\Integration;

use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

class ReadinessFaultTest extends TestCase
{
    /** @var array<string, string> */
    private array $stage;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_NATIVE_FAULT_TESTS') !== '1') {
            $this->markTestSkipped('Enable RUN_NATIVE_FAULT_TESTS for the running isolated staging environment.');
        }
        $root = dirname(__DIR__, 2);
        $this->stage = Dotenv::parse(file_get_contents($root.'/.env.staging'));
        $this->assertSame('servicios_moderno_stage', $this->stage['DB_DATABASE']);
        $this->assertSame('sr_stage_runtime', $this->stage['DB_USERNAME']);
        $this->assertContains($this->stage['DB_HOST'], ['localhost', '127.0.0.1', '::1']);
        $this->assertSame(200, $this->httpStatus('/health/ready'));
    }

    public function test_database_authentication_failure_changes_readiness_without_breaking_liveness(): void
    {
        $root = dirname(__DIR__, 2);
        $admin = Dotenv::parse(file_get_contents($root.'/.tools/environments/admin.env'));
        $pdo = new PDO('mysql:host='.$admin['DB_HOST'].';port='.($admin['DB_PORT'] ?? '3306'),
            $admin['DB_USERNAME'], $admin['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $account = $pdo->quote($this->stage['DB_USERNAME'])."@'localhost'";
        $temporary = $pdo->quote('sr_stage_fault_'.bin2hex(random_bytes(6)))."@'localhost'";
        $pdo->exec('RENAME USER '.$account.' TO '.$temporary);
        try {
            $this->assertSame(503, $this->httpStatus('/health/ready'));
            $this->assertSame(200, $this->httpStatus('/health/live'));
        } finally {
            $pdo->exec('RENAME USER '.$temporary.' TO '.$account);
        }
        $this->assertSame(200, $this->httpStatus('/health/ready'));
    }

    public function test_storage_write_failure_changes_readiness_and_recovers_without_data_loss(): void
    {
        $root = dirname(__DIR__, 2);
        $storage = realpath($this->stage['PRIVATE_STORAGE_ROOT']);
        $parent = realpath($root.'/storage/app/staging');
        $this->assertNotFalse($storage);
        $this->assertSame($parent, dirname($storage));
        $backup = $storage.'-fault-'.bin2hex(random_bytes(6));
        $this->assertSame(dirname($storage), dirname($backup));
        $this->assertTrue(rename($storage, $backup));
        try {
            $this->assertNotFalse(file_put_contents($storage, 'READINESS_FAULT_TEST'));
            $this->assertSame(503, $this->httpStatus('/health/ready'));
            $this->assertSame(200, $this->httpStatus('/health/live'));
        } finally {
            if (is_file($storage) && file_get_contents($storage) === 'READINESS_FAULT_TEST') {
                unlink($storage);
            }
            rename($backup, $storage);
        }
        $this->assertSame(200, $this->httpStatus('/health/ready'));
    }

    private function httpStatus(string $path): int
    {
        $curl = curl_init('https://localhost:8443'.$path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => dirname(__DIR__, 2).'/.tools/caddy-data/pki/authorities/local/root.crt',
        ]);
        try {
            $body = curl_exec($curl);
            $this->assertNotFalse($body, 'Strict TLS staging request must succeed.');
            $this->assertDoesNotMatchRegularExpression('/SQLSTATE|password|token|PDOException/i', $body);

            return curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
    }
}
