<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Residencies\Application\EvidenceWorkflow;
use App\Modules\Residencies\Application\ResidencyDirectory;
use App\Modules\Shared\Application\Ports\VirusScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EvidenceConcurrencyTest extends TestCase
{
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
    }

    /** @return array<string, array{string}> */
    public static function scenarios(): array
    {
        return ['competing submissions' => ['submit'], 'same submission retry' => ['submit-retry'],
            'competing reviews' => ['review'], 'same review retry' => ['review-retry'],
            'review versus replacement' => ['review-replace'], 'same staging reservation' => ['stage']];
    }

    #[DataProvider('scenarios')]
    public function test_independent_processes_preserve_one_submission_or_decision(string $scenario): void
    {
        $administrator = $this->user('ADMIN_RESIDENCIAS');
        $residentUser = $this->user('RESIDENTE');
        $reviewer = $this->user('DRPSS_REVISOR');
        $otherReviewer = $this->user('DRPSS_REVISOR');
        $directory = app(ResidencyDirectory::class);
        $workflow = app(EvidenceWorkflow::class);
        $create = fn (string $kind, array $body): array => $directory->execute($kind, null, null, $body, null, (string) Str::uuid(), $administrator->id, (string) Str::uuid());
        $mode = $create('modalities', ['code' => 'RACE', 'name' => 'Concurrency fixture', 'active' => true]);
        $program = $create('programs', ['code' => 'RACE', 'name' => 'Concurrency fixture', 'active' => true, 'modalityId' => $mode['id']]);
        $resident = $create('residents', ['identityId' => $residentUser->id, 'controlNumber' => '99009901', 'programId' => $program['id'], 'givenNames' => 'Race', 'firstSurname' => 'Fixture']);
        $definition = $workflow->execute('define', null, ['code' => 'RACE', 'name' => 'Concurrency fixture', 'stage' => 'PRERREQUISITO', 'scope' => 'RESIDENTE',
            'required' => true, 'displayOrder' => 0, 'active' => true, 'allowedMimes' => ['text/plain'], 'maxBytes' => 1048576], null, (string) Str::uuid(), $administrator->id, (string) Str::uuid());
        Storage::disk('local')->put('source/input.txt', 'Harmless concurrent evidence fixture.');
        $source = Storage::disk('local')->path('source/input.txt');
        $target = ['residentId' => $resident['id'], 'requirementId' => $definition['id']];
        $stage = fn (): array => $workflow->stage($source, 'proof.txt', $target, (string) Str::uuid(), $residentUser->id, (string) Str::uuid());
        $first = $scenario === 'stage' ? null : $stage();
        $second = $scenario === 'stage' ? null : $stage();
        $body = $target + ['stagedFileId' => $first['id'] ?? '', 'expectedSubmissionVersion' => 0];
        $evidence = str_starts_with($scenario, 'review') ? $workflow->execute('submit', null, $body, null, (string) Str::uuid(), $residentUser->id, (string) Str::uuid()) : null;
        $key = (string) Str::uuid();
        $commands = [];
        for ($i = 0; $i < 2; $i++) {
            $command = ['operation' => 'submit', 'resource' => null, 'body' => array_replace($body, ['stagedFileId' => $i === 0 || $scenario === 'submit-retry' ? ($first['id'] ?? '') : ($second['id'] ?? '')]),
                'key' => $i === 0 || str_ends_with($scenario, '-retry') || $scenario === 'stage' ? $key : (string) Str::uuid(), 'actor' => $residentUser->id];
            if (str_starts_with($scenario, 'review')) {
                $command['operation'] = 'review';
                $command['resource'] = $evidence['id'];
                $command['body'] = ['decision' => 'APROBADO', 'expectedReviewNumber' => 1];
                $command['actor'] = $i === 0 || $scenario === 'review-retry' ? $reviewer->id : $otherReviewer->id;
                if ($i === 1 && $scenario === 'review-replace') {
                    $command['operation'] = 'submit';
                    $command['resource'] = null;
                    $command['actor'] = $residentUser->id;
                    $command['body'] = array_replace($body, ['stagedFileId' => $second['id'], 'expectedSubmissionVersion' => 1]);
                }
            }
            if ($scenario === 'stage') {
                $command['operation'] = 'stage';
                $command['body'] = $target;
            }
            $commands[] = $command;
        }
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'servicios_moderno_test',
            'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'OTEL_ENABLED' => 'false',
            'S7_SOURCE' => $source, 'S7_STORAGE_ROOT' => Storage::disk('local')->path('')];
        $code = <<<'PHP'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='servicios_moderno_test'){exit(2);}
config(['filesystems.disks.local.root'=>getenv('S7_STORAGE_ROOT')]);
$app->instance(App\Modules\Shared\Application\Ports\VirusScanner::class,new class implements App\Modules\Shared\Application\Ports\VirusScanner {public function isClean(string $path):bool{return true;}});
$command=json_decode(getenv('S7_COMMAND'),true,512,JSON_THROW_ON_ERROR);
echo "READY\n";fflush(STDOUT);
try{$service=$app->make(App\Modules\Residencies\Application\EvidenceWorkflow::class);$correlation=(string)Illuminate\Support\Str::uuid();
$result=$command['operation']==='stage'?$service->stage(getenv('S7_SOURCE'),'proof.txt',$command['body'],$command['key'],$command['actor'],$correlation):$service->execute($command['operation'],$command['resource'],$command['body'],null,$command['key'],$command['actor'],$correlation);
echo json_encode(['status'=>201,'id'=>$result['id']],JSON_THROW_ON_ERROR);
}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){echo json_encode(['status'=>$e->getStatusCode()],JSON_THROW_ON_ERROR);}
catch(Throwable $e){echo json_encode(['status'=>'ERROR','class'=>get_class($e)],JSON_THROW_ON_ERROR);exit(1);}
PHP;
        $auditBefore = DB::table('compartido_registros_auditoria')->where('nombre_contexto', 'Residencies')->whereIn('accion', ['SUBMIT', 'REVIEW', 'EVIDENCE_STAGED'])->count();
        $processes = [];
        $primary = DB::connection();
        $primary->beginTransaction();
        try {
            $primary->table('residencias_controles')->where('codigo', 'WRITER')->lockForUpdate()->firstOrFail();
            foreach ($commands as $command) {
                $process = new Process([PHP_BINARY, '-r', $code], base_path(), $environment + ['S7_COMMAND' => json_encode($command, JSON_THROW_ON_ERROR)], timeout: 60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 20;
            do {
                $ready = count(array_filter($processes, fn (Process $process): bool => str_contains($process->getOutput(), 'READY')));
                if ($ready === 2) {
                    break;
                } usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $ready, 'Both independent writers must reach the held barrier.');
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Writer bypassed the held lock.');
            }
            $primary->rollBack();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode(substr($process->getOutput(), strpos($process->getOutput(), '{')), true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            if ($scenario === 'stage') {
                $this->assertContains($statuses, [[201, 201], [201, 409]]);
            } else {
                $this->assertSame(str_ends_with($scenario, '-retry') ? [201, 201] : [201, 409], $statuses);
            }
            if ($statuses === [201, 201]) {
                $this->assertSame($results[0]['id'], $results[1]['id']);
            }
            $this->assertSame($auditBefore + 1, DB::table('compartido_registros_auditoria')->where('nombre_contexto', 'Residencies')->whereIn('accion', ['SUBMIT', 'REVIEW', 'EVIDENCE_STAGED'])->count());
            if ($scenario === 'stage') {
                $this->assertDatabaseCount('residencias_archivos_preparados', 1);
                $this->assertDatabaseCount('compartido_archivos_almacenados', 1);
            } elseif ($scenario === 'review-replace') {
                $latest = DB::table('residencias_entregas_evidencia')->orderByDesc('numero_version')->firstOrFail();
                if ($latest->numero_version === 2) {
                    $this->assertSame('ENVIADO', $latest->estado);
                    $this->assertDatabaseCount('residencias_revisiones_evidencia', 0);
                } else {
                    $this->assertSame('APROBADO', $latest->estado);
                    $this->assertDatabaseCount('residencias_revisiones_evidencia', 1);
                }
            } else {
                $this->assertDatabaseCount('residencias_entregas_evidencia', 1);
                $this->assertDatabaseCount('residencias_revisiones_evidencia', str_starts_with($scenario, 'review') ? 1 : 0);
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

    private function user(string $role): User
    {
        $user = User::factory()->create();
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $user->id, 'id_rol' => DB::table('iam_roles')->where('codigo', $role)->value('id'), 'id_identidad_otorgante' => $user->id, 'otorgado_en' => now()]);

        return $user;
    }
}
