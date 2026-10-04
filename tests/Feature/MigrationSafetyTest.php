<?php

namespace Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class MigrationSafetyTest extends TestCase
{
    public function test_destructive_commands_reject_persistent_database_before_running(): void
    {
        config(['database.connections.sqlite.database' => '/persistent/database.sqlite']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destructive migrations require testing');
        Event::dispatch(new CommandStarting('db:wipe', new ArrayInput([]), new NullOutput));
    }

    public function test_migrate_is_not_blocked_and_in_memory_testing_is_allowed(): void
    {
        Event::dispatch(new CommandStarting('migrate', new ArrayInput([]), new NullOutput));
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }
}
