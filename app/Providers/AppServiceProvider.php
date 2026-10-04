<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! in_array($event->command, ['migrate:fresh', 'migrate:refresh', 'db:wipe'], true)) {
                return;
            }
            $connection = $event->input->hasOption('database') ? $event->input->getOption('database') : null;
            $database = DB::connection(is_string($connection) ? $connection : null);
            $isolated = ($database->getDriverName() === 'sqlite' && $database->getDatabaseName() === ':memory:')
                || ($database->getDriverName() === 'mysql' && $database->getDatabaseName() === 'servicios_moderno_test');
            if (! $this->app->environment('testing') || ! $isolated) {
                throw new RuntimeException('Destructive migrations require testing and the isolated test database.');
            }
        });
    }
}
