<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\AcademicPlanning\Application\LaboratoryCalendar;
use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use App\Modules\LaboratoryRequests\Application\RequestCapture;
use App\Modules\LaboratoryRequests\Application\RequestDecisions;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Shared\Application\Ports\VirusScanner;
use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LaboratoryDecisionConcurrencyTest extends TestCase
{
    private const ROOT = '/api/v1/laboratory-requests';

    private User $administrator;

    private User $teacher;

    private User $technician;

    private string $lab;

    private string $semester;

    private string $career;

    private string $subject;

    private string $group;

    private string $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('servicios_moderno_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        Storage::fake('local');
        $this->app->instance(VirusScanner::class, new class implements VirusScanner
        {
            public function isClean(string $path): bool
            {
                return true;
            }
        });
        $this->travelTo(Carbon::parse('2026-11-13T16:00:00Z'));
        $this->administrator = User::factory()->create();
        $this->teacher = User::factory()->create();
        $this->technician = User::factory()->create();
        foreach ([[$this->administrator, 'ADMIN_PLANIFICACION'], [$this->administrator, 'ADMIN_ORGANIZACION'],
            [$this->administrator, 'ADMIN_LABORATORIOS'], [$this->teacher, 'DOCENTE_SOLICITANTE'], [$this->technician, 'LABORATORISTA']] as [$identity, $role]) {
            $this->grant($identity, $role);
        }

        $employee = app(OrganizationDirectory::class)->execute('createEmployee', null, [
            'identityId' => $this->technician->id, 'givenNames' => 'Laboratorista', 'firstSurname' => 'Ensayo',
        ], $this->administrator->id, (string) Str::uuid());
        $this->semester = $this->planning('semesters', ['code' => 'S9', 'name' => 'Semestre de prueba', 'startsOn' => '2026-11-01', 'endsOn' => '2027-03-31']);
        app(PlanningDirectory::class)->execute('semesters', 'activate', $this->semester, [], 1, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
        $this->career = $this->planning('careers', ['code' => 'S9-C', 'name' => 'Carrera piloto', 'active' => true]);
        $this->subject = $this->planning('subjects', ['code' => 'S9-A', 'name' => 'Asignatura piloto', 'active' => true]);
        $this->planning('curricula', ['careerId' => $this->career, 'subjectId' => $this->subject, 'specialtyId' => null, 'active' => true]);
        $this->group = $this->planning('groups', ['name' => 'Grupo piloto', 'careerId' => $this->career, 'semesterId' => $this->semester, 'active' => true]);
        $this->lab = $this->planning('laboratories', ['code' => 'S9-L', 'name' => 'Laboratorio piloto', 'capacity' => 25, 'status' => 'ACTIVO',
            'careerId' => null, 'responsibleEmployeeId' => $employee['id']]);
        $this->schedule = app(LaboratoryCalendar::class)->publish($this->lab, null, $this->slot(), null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid())['id'];
    }

    private function grant(User $identity, string $role): void
    {
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $identity->id,
            'id_rol' => DB::table('iam_roles')->where('codigo', $role)->value('id'),
            'id_identidad_otorgante' => $this->administrator->id, 'otorgado_en' => now()]);
    }

    private function login(User $user): void
    {
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp,
            'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => $user->version_autorizacion]);
    }

    /** @param array<string, mixed> $data */
    private function planning(string $kind, array $data): string
    {
        return app(PlanningDirectory::class)->execute($kind, 'create', null, $data, null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid())['id'];
    }

    /** @return array<string, mixed> */
    private function slot(): array
    {
        return ['semesterId' => $this->semester, 'weekday' => 1, 'startsAtLocal' => '07:00', 'endsAtLocal' => '11:00'];
    }

    /** @return array<string, mixed> */
    private function input(string $type = 'VARIA'): array
    {
        return ['type' => $type, 'laboratoryId' => $this->lab, 'startAt' => '2026-11-16T07:00:00-06:00', 'endAt' => '2026-11-16T08:00:00-06:00',
            'title' => 'Solicitud de ensayo', 'purpose' => 'Actividad académica', 'attendees' => 12, 'supplies' => 'Material de ensayo'] + match ($type) {
                'PRACTICA' => ['practice' => ['careerId' => $this->career, 'subjectId' => $this->subject, 'groupId' => $this->group, 'name' => 'Práctica piloto', 'number' => '1']],
                'EXTRAORDINARIA' => ['extraordinary' => ['justification' => 'Actividad extraordinaria']],
                default => ['various' => ['useCode' => 'PROYECTO', 'description' => 'Trabajo de equipo']],
            };
    }

    /** @return array<string, string> */
    private function headers(?int $version = null, ?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()] + ($version === null ? [] : ['If-Match' => '"'.$version.'"']);
    }

    public static function scenarios(): array
    {
        return ['overlapping requests, eight writers' => ['overlap', 8], 'same decision, four retries' => ['retry', 4],
            'same request, competing keys' => ['competing', 4], 'cancel versus authorize' => ['cancel', 2],
            'duplicate outbox delivery' => ['effects', 2]];
    }

    #[DataProvider('scenarios')]
    public function test_concurrent_writers_preserve_occupancy_and_idempotent_effects(string $scenario, int $writers): void
    {
        $capture = app(RequestCapture::class);
        $requests = [];
        for ($i = 0; $i < ($scenario === 'overlap' ? $writers : 1); $i++) {
            $draft = $capture->save($this->teacher->id, null, $this->input(), null, (string) Str::uuid(), (string) Str::uuid());
            $requests[] = $capture->submit($this->teacher->id, $draft['id'], 1, (string) Str::uuid(), (string) Str::uuid());
        }
        $claim = null;
        if ($scenario === 'effects') {
            app(RequestDecisions::class)->decide($this->administrator->id, $requests[0]['id'],
                ['decision' => 'AUTORIZADO', 'reason' => 'Prueba concurrente'], 2, (string) Str::uuid(), (string) Str::uuid());
            $claim = app(OutboxProcessor::class)->claim(1)[0];
        }
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'servicios_moderno_test', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'OTEL_ENABLED' => 'false',
            'S10_STORAGE_ROOT' => Storage::disk('local')->path('')];
        $code = <<<'PHP'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='servicios_moderno_test'){exit(2);}
Illuminate\Support\Carbon::setTestNow('2026-11-13T16:00:00Z');
config(['filesystems.disks.local.root'=>getenv('S10_STORAGE_ROOT')]);
$app->instance(App\Modules\Shared\Application\Ports\VirusScanner::class,new class implements App\Modules\Shared\Application\Ports\VirusScanner {
 public function isClean(string $path):bool{return true;}
});
$command=json_decode(getenv('S10_COMMAND'),true,512,JSON_THROW_ON_ERROR);
echo "READY\n";fflush(STDOUT);
try {
 if($command['scenario']==='effects'){
  $app->make(App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor::class)->deliver((object)$command['claim']);
 }else{
  $service=$app->make(App\Modules\LaboratoryRequests\Application\RequestDecisions::class);
  if($command['operation']==='cancel'){$service->cancel($command['actor'],$command['id'],'Cancelación de ensayo',2,$command['key'],(string)Illuminate\Support\Str::uuid());}
  else{$service->decide($command['actor'],$command['id'],['decision'=>'AUTORIZADO','reason'=>'Prueba concurrente'],2,$command['key'],(string)Illuminate\Support\Str::uuid());}
 }
 echo json_encode(['status'=>200],JSON_THROW_ON_ERROR);
}catch(Symfony\Component\HttpKernel\Exception\HttpException $exception){echo json_encode(['status'=>$exception->getStatusCode()],JSON_THROW_ON_ERROR);}
catch(Throwable $exception){echo json_encode(['status'=>'ERROR','class'=>get_class($exception)],JSON_THROW_ON_ERROR);exit(1);}
PHP;
        $processes = [];
        $primary = DB::connection();
        $primary->beginTransaction();
        try {
            if ($claim !== null) {
                $primary->table('compartido_mensajes_salida')->where('id', $claim->id)->lockForUpdate()->firstOrFail();
            } else {
                $primary->table('laboratorios_controles')->where('codigo', 'WRITER')->lockForUpdate()->firstOrFail();
            }
            $key = (string) Str::uuid();
            for ($i = 0; $i < $writers; $i++) {
                $cancel = $scenario === 'cancel' && $i === 0;
                $command = ['scenario' => $scenario, 'operation' => $cancel ? 'cancel' : 'decide',
                    'actor' => $cancel ? $this->teacher->id : $this->administrator->id,
                    'id' => $requests[$scenario === 'overlap' ? $i : 0]['id'], 'key' => $scenario === 'retry' ? $key : (string) Str::uuid(), 'claim' => $claim];
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=256M', '-r', $code], base_path(),
                    $environment + ['S10_COMMAND' => json_encode($command, JSON_THROW_ON_ERROR)], timeout: 60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 25;
            do {
                $ready = count(array_filter($processes, fn ($process): bool => str_contains($process->getOutput(), 'READY')));
                if ($ready === $writers) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame($writers, $ready, 'All writers must reach the held database barrier.');
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning());
            }
            $primary->rollBack();
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $output = $process->getOutput();
                $result = json_decode(substr($output, strpos($output, '{')), true, flags: JSON_THROW_ON_ERROR);
                $statuses[] = $result['status'];
            }
            sort($statuses);
            $expected = in_array($scenario, ['retry', 'effects'], true) ? array_fill(0, $writers, 200) : [200, ...array_fill(0, $writers - 1, 409)];
            $this->assertSame($expected, $statuses);
            $this->assertDatabaseCount('laboratorios_decisiones_solicitud', 1);
            $this->assertSame(1, DB::table('compartido_mensajes_salida')->where('nombre_contexto', 'LaboratoryRequests')->count());
            $this->assertLessThanOrEqual(1, DB::table('laboratorios_solicitudes')->where('estado', 'AUTORIZADO')->count());
            if ($scenario === 'effects') {
                $this->assertDatabaseCount('laboratorios_comprobantes', 1);
                $this->assertDatabaseCount('laboratorios_notificaciones', 1);
                $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $claim->id, 'estado' => 'PUBLISHED']);
                $this->assertDatabaseCount('compartido_archivos_almacenados', 1);
            }
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }
}
