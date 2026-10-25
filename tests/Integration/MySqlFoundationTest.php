<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\AcademicPlanning\Application\LaboratoryCalendar;
use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use App\Modules\IAM\Application\AuthorizationAdministration;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Publications\Application\DTO\NoticeInput;
use App\Modules\Publications\Application\NoticeService;
use App\Modules\Residencies\Application\ResidencyDirectory;
use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlFoundationTest extends TestCase
{
    /** @return array{string, string, string} */
    private function calendarFixture(): array
    {
        $actor = User::factory()->create();
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $actor->id,
            'id_rol' => DB::table('iam_roles')->where('codigo', 'ADMIN_PLANIFICACION')->value('id'),
            'id_identidad_otorgante' => $actor->id, 'otorgado_en' => now()]);
        $service = app(PlanningDirectory::class);
        $semester = $service->execute('semesters', 'create', null, ['code' => 'RACE', 'name' => 'Race fixture', 'startsOn' => '2026-10-01', 'endsOn' => '2026-11-30'], null, (string) Str::uuid(), $actor->id, (string) Str::uuid());
        $service->execute('semesters', 'activate', $semester['id'], [], 1, (string) Str::uuid(), $actor->id, (string) Str::uuid());
        $lab = $service->execute('laboratories', 'create', null, ['code' => 'RACE', 'name' => 'Race fixture', 'status' => 'ACTIVO', 'capacity' => 20], null, (string) Str::uuid(), $actor->id, (string) Str::uuid());

        return [$actor->id, $semester['id'], $lab['id']];
    }

    /** @return array<string, array{bool}> */
    public static function scheduleRaceModes(): array
    {
        return ['different keys compete for the same interval' => [false], 'same key retries one publication' => [true]];
    }

    #[DataProvider('scheduleRaceModes')]
    public function test_schedule_publishers_serialize_conflicts_and_retries(bool $sameKey): void
    {
        [$actor, $semester, $laboratory] = $this->calendarFixture();
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'servicios_moderno_test',
            'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'OTEL_ENABLED' => 'false',
            'SCHEDULE_ACTOR' => $actor, 'SCHEDULE_SEMESTER' => $semester, 'SCHEDULE_LAB' => $laboratory];
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'servicios_moderno_test' || !$app->environment('testing')) { exit(2); }
echo "READY\n"; fflush(STDOUT);
try {
    $record = $app->make(App\Modules\AcademicPlanning\Application\LaboratoryCalendar::class)->publish(getenv('SCHEDULE_LAB'), null,
        ['semesterId'=>getenv('SCHEDULE_SEMESTER'),'weekday'=>1,'startsAtLocal'=>'07:00','endsAtLocal'=>'10:00'], null,
        getenv('SCHEDULE_KEY'), getenv('SCHEDULE_ACTOR'), (string) Illuminate\Support\Str::uuid());
    echo json_encode(['status'=>201,'id'=>$record['id']],JSON_THROW_ON_ERROR);
} catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    echo json_encode(['status'=>$exception->getStatusCode()],JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status'=>'ERROR','class'=>get_class($exception)],JSON_THROW_ON_ERROR); exit(1);
}
PHP;
        $key = (string) Str::uuid();
        $processes = [];
        $primary = DB::connection('mysql');
        $primary->beginTransaction();
        try {
            $primary->table('planificacion_controles')->where('codigo', 'WRITER')->lockForUpdate()->first();
            foreach ([$key, $sameKey ? $key : (string) Str::uuid()] as $requestKey) {
                $process = new Process([PHP_BINARY, '-r', $code], base_path(), $environment + ['SCHEDULE_KEY' => $requestKey], timeout: 30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 20;
            do {
                $ready = count(array_filter($processes, fn (Process $process): bool => str_contains($process->getOutput(), 'READY')));
                if ($ready === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $ready, 'Both publishers must reach the barrier.');
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Writer bypassed the held transaction lock.');
            }
            $primary->rollBack();
            $statuses = [];
            $ids = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), 'Concurrent publisher did not complete successfully.');
                $lines = preg_split('/\R/', trim($process->getOutput()));
                $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
                $statuses[] = $result['status'];
                if (isset($result['id'])) {
                    $ids[] = $result['id'];
                }
            }
            sort($statuses);
            $this->assertSame($sameKey ? [201, 201] : [201, 409], $statuses);
            $this->assertCount(1, array_unique($ids));
            $this->assertDatabaseCount('planificacion_horarios', 1);
            $this->assertSame(1, DB::table('compartido_registros_auditoria')->where('tipo_sujeto', 'schedules')->count());
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_schedule_adoption_rejects_populated_tables_before_changing_any_data(): void
    {
        [$actor, $semester, $laboratory] = $this->calendarFixture();
        $record = app(LaboratoryCalendar::class)->publish($laboratory, null,
            ['semesterId' => $semester, 'weekday' => 1, 'startsAtLocal' => '07:00', 'endsAtLocal' => '10:00'], null, (string) Str::uuid(), $actor, (string) Str::uuid());
        $indexes = Schema::getIndexes('planificacion_horarios');
        $migration = require app_path('Modules/AcademicPlanning/Infrastructure/Persistence/Migrations/2026_10_24_053814_activate_planning_schedules.php');
        try {
            $migration->up();
            $this->fail('Populated schedules were silently adopted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Populated schedule baseline', $exception->getMessage());
        }
        $this->assertSame($indexes, Schema::getIndexes('planificacion_horarios'));
        $this->assertDatabaseHas('planificacion_horarios', ['id' => $record['id'], 'version' => 1, 'activo' => true]);
    }

    public function test_populated_planning_baseline_is_preserved_and_requires_reconciliation(): void
    {
        $this->artisan('db:wipe', ['--force' => true])->assertSuccessful();
        $sql = file_get_contents(database_path('mysql/001_create_servicios_moderno.sql'));
        $sql = preg_replace('/CREATE DATABASE `servicios_moderno`.*?;\s*USE `servicios_moderno`;/s', '', $sql, 1, $replacements);
        $this->assertSame(1, $replacements);
        $this->assertSame('servicios_moderno_test', DB::connection()->getDatabaseName());
        DB::unprepared($sql);
        DB::table('planificacion_carreras')->insert(['id' => (string) Str::ulid(), 'codigo' => 'CONSERVAR', 'nombre' => 'Baseline con datos', 'activo' => true]);
        try {
            $this->artisan('migrate', ['--force' => true])->run();
            $this->fail('Populated Planning baseline was silently adopted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Populated Planning baseline', $exception->getMessage());
        }
        $this->assertDatabaseHas('planificacion_carreras', ['codigo' => 'CONSERVAR', 'nombre' => 'Baseline con datos']);
        $this->assertFalse(Schema::hasColumn('planificacion_carreras', 'version'));
    }

    public function test_planning_writer_lock_serializes_activation_and_idempotent_creation(): void
    {
        $actor = User::factory()->create();
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $actor->id,
            'id_rol' => DB::table('iam_roles')->where('codigo', 'ADMIN_PLANIFICACION')->value('id'),
            'id_identidad_otorgante' => $actor->id, 'otorgado_en' => now()]);
        $service = app(PlanningDirectory::class);
        $input = ['code' => '2026-2', 'name' => 'Semestre de ensayo', 'startsOn' => '2026-08-01', 'endsOn' => '2026-12-18'];
        $key = (string) Str::uuid();
        $first = $service->execute('semesters', 'create', null, $input, null, $key, $actor->id, (string) Str::uuid());
        $this->assertSame($first, $service->execute('semesters', 'create', null, $input, null, $key, $actor->id, (string) Str::uuid()));
        $input['code'] = '2027-1';
        $second = $service->execute('semesters', 'create', null, $input, null, (string) Str::uuid(), $actor->id, (string) Str::uuid());
        config(['database.connections.planning_contender' => config('database.connections.mysql')]);
        DB::purge('planning_contender');
        DB::connection('planning_contender')->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $primary = DB::connection('mysql');
        $primary->beginTransaction();
        try {
            $primary->table('planificacion_controles')->where('codigo', 'WRITER')->lockForUpdate()->first();
            DB::setDefaultConnection('planning_contender');
            try {
                $service->execute('semesters', 'activate', $second['id'], [], 1, (string) Str::uuid(), $actor->id, (string) Str::uuid());
                $this->fail('Concurrent activation bypassed the Planning writer lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1] ?? null);
            }
        } finally {
            DB::setDefaultConnection('mysql');
            $primary->rollBack();
            DB::disconnect('planning_contender');
        }
        $service->execute('semesters', 'activate', $first['id'], [], 1, (string) Str::uuid(), $actor->id, (string) Str::uuid());
        try {
            $service->execute('semesters', 'activate', $second['id'], [], 1, (string) Str::uuid(), $actor->id, (string) Str::uuid());
            $this->fail('A second active semester was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(1, DB::table('planificacion_semestres')->where('estado', 'ACTIVO')->count());
        $this->assertSame(3, DB::table('compartido_registros_auditoria')->where('nombre_contexto', 'AcademicPlanning')->count());
    }

    public function test_publication_row_and_idempotency_locks_serialize_writers_and_preserve_versions(): void
    {
        $actor = User::factory()->create();
        $role = DB::table('iam_roles')->where('codigo', 'ADMIN_PUBLICACIONES')->value('id');
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $actor->id, 'id_rol' => $role,
            'id_identidad_otorgante' => $actor->id, 'otorgado_en' => now()]);
        $service = app(NoticeService::class);
        $input = new NoticeInput('EMPLEO', 'Concurrent notice', 'Fixture', '', null, null, null, null);
        $key = (string) Str::uuid();
        $notice = $service->command('create', null, $input, null, $key, $actor->id, (string) Str::uuid());
        config(['database.connections.publication_contender' => config('database.connections.mysql')]);
        DB::purge('publication_contender');
        DB::connection('publication_contender')->statement('SET SESSION innodb_lock_wait_timeout = 1');
        foreach (['publicaciones_avisos', 'publicaciones_claves_idempotencia'] as $table) {
            $primary = DB::connection('mysql');
            $primary->beginTransaction();
            try {
                $primary->table($table)->lockForUpdate()->first();
                DB::setDefaultConnection('publication_contender');
                try {
                    $service->command($table === 'publicaciones_avisos' ? 'update' : 'create', $table === 'publicaciones_avisos' ? $notice['id'] : null,
                        $input, $table === 'publicaciones_avisos' ? 1 : null, $table === 'publicaciones_avisos' ? null : $key, $actor->id, (string) Str::uuid());
                    $this->fail('Concurrent Publications write bypassed its row lock.');
                } catch (QueryException $exception) {
                    $this->assertSame(1205, $exception->errorInfo[1] ?? null);
                }
            } finally {
                DB::setDefaultConnection('mysql');
                $primary->rollBack();
            }
        }
        DB::disconnect('publication_contender');
        $this->assertDatabaseCount('publicaciones_avisos', 1);
        $this->assertSame($notice, $service->command('create', null, $input, null, $key, $actor->id, (string) Str::uuid()));
        $updated = $service->command('update', $notice['id'], $input, 1, null, $actor->id, (string) Str::uuid());
        $this->assertSame(2, $updated['version']);
        try {
            $service->command('update', $notice['id'], $input, 1, null, $actor->id, (string) Str::uuid());
            $this->fail('Stale Publications version was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public static function residencyRaceModes(): array
    {
        return ['one project per period' => ['period'], 'inclusive overlap across periods' => ['overlap'], 'idempotent concurrent retry' => ['retry'], 'global RFC collision' => ['company']];
    }

    #[DataProvider('residencyRaceModes')]
    public function test_residency_concurrent_creation_keeps_uniqueness_and_intervals(string $mode): void
    {
        $actor = User::factory()->create();
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $actor->id, 'id_rol' => DB::table('iam_roles')->where('codigo', 'ADMIN_RESIDENCIAS')->value('id'), 'id_identidad_otorgante' => $actor->id, 'otorgado_en' => now()]);
        $service = app(ResidencyDirectory::class);
        $create = fn (string $kind, array $body): array => $service->execute($kind, null, null, $body, null, (string) Str::uuid(), $actor->id, (string) Str::uuid());
        $modality = $create('modalities', ['code' => 'RACE', 'name' => 'Fixture', 'active' => true]);
        $program = $create('programs', ['code' => 'RACE', 'name' => 'Fixture', 'active' => true, 'modalityId' => $modality['id']]);
        $period = $create('periods', ['code' => 'RACE', 'name' => 'Fixture', 'startsOn' => '2026-08-01', 'endsOn' => '2026-12-31', 'durationMonths' => 5, 'status' => 'ACTIVO']);
        $second = $create('periods', ['code' => 'OTHER', 'name' => 'Fixture', 'startsOn' => '2026-10-01', 'endsOn' => '2027-03-31', 'durationMonths' => 6, 'status' => 'ACTIVO']);
        $company = $create('companies', ['name' => 'Race fixture', 'rfc' => 'XAXX010101000', 'status' => 'ACTIVO']);
        $resident = $create('residents', ['identityId' => $actor->id, 'programId' => $program['id'], 'controlNumber' => '21000001', 'givenNames' => 'Persona', 'firstSurname' => 'Ensayo']);
        $kind = $mode === 'company' ? 'companies' : 'projects';
        $body = $mode === 'company' ? ['name' => 'Race fixture', 'rfc' => 'XEXX010101000', 'status' => 'ACTIVO'] : ['residentId' => $resident['id'], 'periodId' => $period['id'], 'companyId' => $company['id'], 'programId' => $program['id'], 'name' => 'Race fixture', 'vacancies' => 1, 'startsOn' => '2026-10-01', 'endsOn' => '2026-12-31'];
        $bodies = [$body, $mode === 'overlap' ? array_replace($body, ['periodId' => $second['id']]) : $body];
        $table = $mode === 'company' ? 'residencias_empresas' : 'residencias_proyectos';
        $beforeRows = DB::table($table)->count();
        $beforeAudits = DB::table('compartido_registros_auditoria')->where('nombre_contexto', 'Residencies')->where('tipo_sujeto', $kind)->count();
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'servicios_moderno_test', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'OTEL_ENABLED' => 'false', 'RESIDENCY_ACTOR' => $actor->id, 'RESIDENCY_KIND' => $kind];
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='servicios_moderno_test' || !$app->environment('testing')){exit(2);}
echo "READY\n";fflush(STDOUT);
try {
    $record=$app->make(App\Modules\Residencies\Application\ResidencyDirectory::class)->execute(getenv('RESIDENCY_KIND'),null,null,json_decode(getenv('RESIDENCY_BODY'),true,flags:JSON_THROW_ON_ERROR),null,getenv('RESIDENCY_KEY'),getenv('RESIDENCY_ACTOR'),(string)Illuminate\Support\Str::uuid());
    echo json_encode(['status'=>201,'id'=>$record['id']],JSON_THROW_ON_ERROR);
}catch(Symfony\Component\HttpKernel\Exception\HttpException $exception){echo json_encode(['status'=>$exception->getStatusCode()],JSON_THROW_ON_ERROR);}
catch(Throwable $exception){echo json_encode(['status'=>'ERROR','class'=>get_class($exception)],JSON_THROW_ON_ERROR);exit(1);}
PHP;
        $key = (string) Str::uuid();
        $processes = [];
        $primary = DB::connection('mysql');
        $primary->beginTransaction();
        try {
            $primary->table('residencias_controles')->where('codigo', 'WRITER')->lockForUpdate()->first();
            foreach ($bodies as $index => $input) {
                $process = new Process([PHP_BINARY, '-r', $code], base_path(), $environment + ['RESIDENCY_BODY' => json_encode($input, JSON_THROW_ON_ERROR), 'RESIDENCY_KEY' => $index === 0 || $mode === 'retry' ? $key : (string) Str::uuid()], timeout: 30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 20;
            do {
                $ready = count(array_filter($processes, fn (Process $process): bool => str_contains($process->getOutput(), 'READY')));
                if ($ready === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $ready);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning());
            }
            $primary->rollBack();
            $statuses = [];
            $ids = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait());
                $lines = preg_split('/\R/', trim($process->getOutput()));
                $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
                $statuses[] = $result['status'];
                if (isset($result['id'])) {
                    $ids[] = $result['id'];
                }
            }
            sort($statuses);
            $this->assertSame($mode === 'retry' ? [201, 201] : [201, 409], $statuses);
            $this->assertCount(1, array_unique($ids));
            $this->assertSame($beforeRows + 1, DB::table($table)->count());
            $this->assertSame($beforeAudits + 1, DB::table('compartido_registros_auditoria')->where('nombre_contexto', 'Residencies')->where('tipo_sujeto', $kind)->count());
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_residency_adoption_rejects_populated_tables_before_ddl(): void
    {
        $id = (string) Str::ulid();
        DB::table('residencias_sectores')->insert(['id' => $id, 'codigo' => 'GUARD', 'nombre' => 'Guard fixture', 'activo' => true, 'version' => 1]);
        $columns = Schema::getColumns('residencias_sectores');
        $migration = require glob(app_path('Modules/Residencies/Infrastructure/Persistence/Migrations/*create_residencies_foundation.php'))[0];
        try {
            $migration->up();
            $this->fail('Populated baseline accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('requires reconciliation', $exception->getMessage());
        }
        $this->assertSame($columns, Schema::getColumns('residencias_sectores'));
        $this->assertDatabaseHas('residencias_sectores', ['id' => $id, 'codigo' => 'GUARD']);
    }

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
            foreach ($privileges['runtimeMutable'] as $table) {
                $admin->exec('GRANT SELECT, INSERT, UPDATE ON servicios_moderno_test.`'.$table.'` TO '.$runtime);
            }
            foreach ($privileges['runtimeAppendOnly'] as $table) {
                $admin->exec('GRANT SELECT, INSERT ON servicios_moderno_test.`'.$table.'` TO '.$runtime);
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
            foreach (['UPDATE residencias_revisiones_evidencia SET numero_revision=numero_revision WHERE 1=0', 'DELETE FROM residencias_revisiones_evidencia WHERE 1=0', 'DELETE FROM residencias_entregas_evidencia WHERE 1=0', 'DELETE FROM residencias_archivos_preparados WHERE 1=0'] as $sql) {
                try {
                    $limited->exec($sql);
                    $this->fail('Evidence history or retention privilege unexpectedly granted.');
                } catch (PDOException $exception) {
                    $this->assertSame(1142, $exception->errorInfo[1]);
                }
            }
            try {
                $operatorConnection->query('SELECT COUNT(*) FROM residencias_revisiones_evidencia');
                $this->fail('IAM operator obtained documentary access.');
            } catch (PDOException $exception) {
                $this->assertSame(1142, $exception->errorInfo[1]);
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
