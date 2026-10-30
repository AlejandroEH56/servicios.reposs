<?php

namespace Tests\Integration;

use App\Modules\Shared\Application\Ports\VirusScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CompletionFixture;
use Tests\TestCase;

class CompletionConcurrencyTest extends TestCase
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

    /** @return array<string,array{string}> */
    public static function scenarios(): array
    {
        return ['competing resolution' => ['release'], 'resolution replay' => ['release-retry'], 'competing reopening' => ['reopen'],
            'certificate folios' => ['folio'], 'certificate replay' => ['folio-retry'], 'documentary reopening' => ['evidence-reopen'],
            'resolve versus reopen' => ['resolve-reopen'], 'duplicate PDF jobs' => ['render']];
    }

    #[DataProvider('scenarios')]
    public function test_independent_writers_preserve_release_history_and_unique_certificate_folios(string $scenario): void
    {
        $fixture = new CompletionFixture;
        $fixture->initializeAndApprove();
        $fixture->completeReports();
        $command = ['service' => 'progress', 'operation' => 'release-resolve', 'resource' => $fixture->project, 'body' => ['decision' => 'APROBADO', 'observations' => 'Validación de concurrencia', 'expectedVersion' => 0], 'actor' => $fixture->administrator->id];
        if (in_array($scenario, ['reopen', 'resolve-reopen'], true)) {
            $fixture->release();
            $command['operation'] = 'release-reopen';
            $command['body'] = ['observations' => 'Reapertura de ensayo', 'expectedVersion' => 1];
        }
        if (str_starts_with($scenario, 'folio')) {
            $fixture->release();
            $template = $fixture->template('CONSTANCIA_ASESORIA');
            $command['service'] = 'documents';
            $command['operation'] = 'issue';
            $command['body'] = ['type' => 'CONSTANCIA_ASESORIA', 'templateId' => $template['id'], 'expectedTemplateVersion' => 2, 'adviserAssignmentId' => $fixture->assignment, 'year' => 2026, 'semester' => 1, 'startsOn' => '2026-02-01', 'endsOn' => '2026-07-31'];
        }
        if ($scenario === 'evidence-reopen') {
            $first = $fixture->plan()['items'][0];
            $command['operation'] = 'evidence-reopen';
            $command['resource'] = $first['evidenceId'];
            $command['body'] = ['observations' => 'Reapertura concurrente', 'expectedReviewNumber' => 1];
        }
        if ($scenario === 'render') {
            $document = $fixture->issue($fixture->template());
            $command['service'] = 'documents';
            $command['operation'] = 'render';
            $command['resource'] = $document['id'];
            $command['body'] = [];
        }
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'servicios_moderno_test', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'OTEL_ENABLED' => 'false', 'S8_STORAGE_ROOT' => Storage::disk('local')->path('')];
        $code = <<<'PHP'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='servicios_moderno_test'){exit(2);}
config(['filesystems.disks.local.root'=>getenv('S8_STORAGE_ROOT')]);
$app->instance(App\Modules\Shared\Application\Ports\VirusScanner::class,new class implements App\Modules\Shared\Application\Ports\VirusScanner {public function isClean(string $path):bool{return true;}});
$app->instance(App\Modules\Residencies\Application\Ports\DocumentRenderer::class,new class implements App\Modules\Residencies\Application\Ports\DocumentRenderer {
 public function html(array $snapshot):string{return app(App\Modules\Residencies\Infrastructure\Documents\DompdfRenderer::class)->html($snapshot);}
 public function pdf(string $html):string{usleep(200000);return "%PDF-1.4\n% ".hash('sha256',$html)."\n%%EOF";}
});
$command=json_decode(getenv('S8_COMMAND'),true,512,JSON_THROW_ON_ERROR);echo "READY\n";fflush(STDOUT);
try{$service=$app->make($command['service']==='documents'?App\Modules\Residencies\Application\DocumentWorkflow::class:App\Modules\Residencies\Application\ProgressWorkflow::class);
if($command['operation']==='render'){$service->render($command['resource']);$result=['id'=>$command['resource']];}
else{$result=$service->execute($command['operation'],$command['resource'],$command['body'],$command['key'],$command['actor'],(string)Illuminate\Support\Str::uuid());}
echo json_encode(['status'=>201,'id'=>$result['id']??$result['evidenceId']??null,'folio'=>$result['folio']??null],JSON_THROW_ON_ERROR);
}catch(Symfony\Component\HttpKernel\Exception\HttpException $exception){echo json_encode(['status'=>$exception->getStatusCode()],JSON_THROW_ON_ERROR);}
catch(Throwable $exception){echo json_encode(['status'=>'ERROR','class'=>get_class($exception)],JSON_THROW_ON_ERROR);exit(1);}
PHP;
        $key = (string) Str::uuid();
        $processes = [];
        $primary = DB::connection();
        $primary->beginTransaction();
        try {
            $primary->table('residencias_controles')->where('codigo', 'WRITER')->lockForUpdate()->firstOrFail();
            for ($i = 0; $i < 2; $i++) {
                $current = $command;
                if ($scenario === 'resolve-reopen' && $i === 0) {
                    $current['operation'] = 'release-resolve';
                    $current['body'] = ['decision' => 'APROBADO', 'observations' => 'Resolución concurrente obsoleta', 'expectedVersion' => 1];
                }
                $process = new Process([PHP_BINARY, '-r', $code], base_path(), $environment + ['S8_COMMAND' => json_encode($current + ['key' => $i === 0 || str_ends_with($scenario, '-retry') ? $key : (string) Str::uuid()], JSON_THROW_ON_ERROR)], timeout: 60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 20;
            do {
                $ready = count(array_filter($processes, fn (Process $p): bool => str_contains($p->getOutput(), 'READY')));
                if ($ready === 2) {
                    break;
                }usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $ready, 'Both processes must reach the held writer barrier.');
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning());
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
            $this->assertSame(str_starts_with($scenario, 'folio') || str_ends_with($scenario, '-retry') || $scenario === 'render' ? [201, 201] : [201, 409], $statuses);
            if (str_starts_with($scenario, 'folio')) {
                $this->assertDatabaseCount('residencias_documentos_emitidos', $scenario === 'folio' ? 2 : 1);
                $this->assertDatabaseCount('residencias_constancias', $scenario === 'folio' ? 2 : 1);
                $folios = DB::table('residencias_constancias')->orderBy('numero_consecutivo')->pluck('folio')->all();
                $this->assertSame($scenario === 'folio' ? ['ITSH/RPSS/2026(1)/001', 'ITSH/RPSS/2026(1)/002'] : ['ITSH/RPSS/2026(1)/001'], $folios);
            } elseif ($scenario === 'render') {
                $this->assertDatabaseHas('residencias_documentos_emitidos', ['id' => $command['resource'], 'estado' => 'LISTO']);
                $this->assertDatabaseCount('compartido_archivos_almacenados', 4);
                $this->assertSame(1, DB::table('compartido_registros_auditoria')->where('accion', 'DOCUMENT_READY')->count());
            } elseif ($scenario === 'evidence-reopen') {
                $this->assertDatabaseCount('residencias_reaperturas_evidencia', 1);
                $this->assertDatabaseCount('residencias_revisiones_evidencia', 3);
            } else {
                $this->assertDatabaseCount('residencias_liberaciones', 1);
                $this->assertDatabaseCount('residencias_historial_liberaciones', in_array($scenario, ['reopen', 'resolve-reopen'], true) ? 2 : 1);
            }
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }
}
