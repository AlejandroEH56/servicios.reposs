<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('modernization:prepare-test-database')]
#[Description('Creates the authorized isolated database, without dropping any database')]
class PrepareTestDatabase extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! app()->environment('local', 'testing') || DB::getDriverName() !== 'mysql'
            || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Test database preparation is restricted to local MySQL.');
        }
        DB::statement('CREATE DATABASE IF NOT EXISTS servicios_moderno_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->info('Isolated database ready: servicios_moderno_test');

        return self::SUCCESS;
    }
}
