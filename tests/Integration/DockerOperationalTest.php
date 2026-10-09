<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DockerOperationalTest extends TestCase
{
    private string $root;

    private string $docker;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_DOCKER_TESTS') !== '1') {
            $this->markTestSkipped('Requires isolated servicios_stage Docker topology.');
        }
        $this->root = dirname(__DIR__, 2);
        $this->docker = getenv('DOCKER_EXECUTABLE') ?: 'docker';
        $this->assertSame(200, $this->httpStatus('/health/ready'));
    }

    public function test_database_storage_failures_and_recovery(): void
    {
        $this->sql("RENAME USER 'sr_container_runtime'@'%' TO 'sr_container_fault'@'%'");
        try {
            $this->assertSame(503, $this->httpStatus('/health/ready'));
            $this->assertSame(200, $this->httpStatus('/health/live'));
        } finally {
            $this->sql("RENAME USER 'sr_container_fault'@'%' TO 'sr_container_runtime'@'%'");
        }
        $this->assertSame(200, $this->httpStatus('/health/ready'));
        $this->composeOperation(['exec', '-T', 'backend', 'chmod', '500', 'storage/app/private/.health']);
        try {
            $this->assertSame(503, $this->httpStatus('/health/ready'));
            $this->assertSame(200, $this->httpStatus('/health/live'));
        } finally {
            $this->composeOperation(['exec', '-T', 'backend', 'chmod', '700', 'storage/app/private/.health']);
        }
        $this->assertSame(200, $this->httpStatus('/health/ready'));
    }

    public function test_runtime_denies_ddl_audit_mutations_and_global_accounts(): void
    {
        $r = $this->php(<<<'PHP'
$pdo=Illuminate\Support\Facades\DB::connection()->getPdo();$denied=0;
foreach(['CREATE TABLE runtime_forbidden_probe(id INT)','DELETE FROM compartido_registros_auditoria WHERE 1=0','UPDATE compartido_registros_auditoria SET accion=accion WHERE 1=0','SELECT User FROM mysql.user'] as $sql){try{$pdo->exec($sql);}catch(PDOException $e){if(in_array($e->errorInfo[1],[1142,1044],true)){$denied++;}}}echo json_encode(['denied'=>$denied]);
PHP);
        $this->assertSame(4, $r['denied']);
    }

    public function test_real_scanner_clean_eicar_and_unavailable(): void
    {
        $r = $this->php(<<<'PHP'
$p=tempnam('/tmp','scan-');$s=new App\Modules\Shared\Infrastructure\Storage\ClamAvScanner;
try{file_put_contents($p,'Harmless integration fixture.');$clean=$s->isClean($p);file_put_contents($p,base64_decode('WDVPIVAlQEFQWzRcUFpYNTQoUF4pN0NDKTd9JEVJQ0FSLVNUQU5EQVJELUFOVElWSVJVUy1URVNULUZJTEUhJEgrSCo='));$infected=!$s->isClean($p);config(['modernization.storage.scanner_host'=>'127.0.0.1','modernization.storage.scanner_port'=>1]);try{$s->isClean($p);$closed=false;}catch(RuntimeException $e){$closed=$e->getMessage()==='SCANNER_UNAVAILABLE';}echo json_encode(['clean'=>$clean,'infected'=>$infected,'closed'=>$closed]);}finally{unlink($p);}
PHP);
        $this->assertTrue($r['clean']);
        $this->assertTrue($r['infected']);
        $this->assertTrue($r['closed']);
    }

    public function test_real_scanner_timeout(): void
    {
        $server = $this->process(['exec', '-T', 'backend', 'php', '-r', '$s=stream_socket_server("tcp://127.0.0.1:13310");$c=stream_socket_accept($s,10);if($c){sleep(8);fclose($c);}fclose($s);']);
        $server->start();
        try {
            usleep(750000);
            $r = $this->php(<<<'PHP'
config(['modernization.storage.scanner_host'=>'127.0.0.1','modernization.storage.scanner_port'=>13310]);$p=tempnam('/tmp','timeout-');file_put_contents($p,'Harmless fixture.');try{(new App\Modules\Shared\Infrastructure\Storage\ClamAvScanner)->isClean($p);echo json_encode(['closed'=>false]);}catch(RuntimeException $e){echo json_encode(['closed'=>$e->getMessage()==='SCANNER_TIMEOUT']);}finally{unlink($p);}
PHP);
            $this->assertTrue($r['closed']);
        } finally {
            $server->wait();
        }
    }

    public function test_collector_fail_open_and_recovery(): void
    {
        $this->composeOperation(['stop', 'collector']);
        try {
            $this->assertSame(200, $this->httpStatus('/health/live'));
            $this->assertSame(200, $this->httpStatus('/health/ready'));
            $this->assertSame(401, $this->httpStatus('/api/v1/me'));
        } finally {
            $this->composeOperation(['start', 'collector']);
        }
        $this->assertSame(200, $this->httpStatus('/health/ready'));
    }

    public function test_stopped_worker_missing_heartbeat_and_recovery(): void
    {
        $this->composeOperation(['stop', 'worker']);
        try {
            $this->php("Illuminate\\Support\\Facades\\Cache::forget(config('modernization.health.outbox_heartbeat_key'));echo '{}';");
            $this->assertSame(503, $this->httpStatus('/health/ready'));
            $this->assertSame(200, $this->httpStatus('/health/live'));
        } finally {
            $this->composeOperation(['start', 'worker']);
        }
        for ($i = 0; $i < 20; $i++) {
            if ($this->httpStatus('/health/ready') === 200) {
                break;
            }usleep(500000);
        }$this->assertSame(200, $this->httpStatus('/health/ready'));
    }

    public function test_real_outbox_reclaims_deduplicates_and_replays(): void
    {
        $this->composeOperation(['stop', 'worker']);
        try {
            $result = $this->php(<<<'PHP'
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
$id=(string)Str::ulid();$key='operations.probe.'.$id;
if(DB::table('compartido_mensajes_salida')->whereIn('estado',['PENDING','PROCESSING'])->exists()){throw new RuntimeException('PROBE_REQUIRES_IDLE_OUTBOX');}
try{
 DB::table('compartido_mensajes_salida')->insert(['id'=>$id,'nombre_contexto'=>'OperationsProbe','tipo_agregado'=>'Probe','id_agregado'=>$id,'tipo_evento'=>'operations.probe.v1','contenido'=>json_encode(['eventId'=>$id,'type'=>'operations.probe.v1','version'=>1,'occurredAt'=>now()->toIso8601String()]),'estado'=>'PENDING','ocurrido_en'=>now(),'disponible_en'=>now()]);
 $handler=function(array $payload)use($key){DB::table('cache')->insert(['key'=>$key,'value'=>'1','expiration'=>now()->addHour()->timestamp]);};
 $processor=new App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor(['operations.probe.v1'=>['operations.probe'=>$handler]]);
 $claim=$processor->claim(1)[0];$processor->deliver($claim);
 DB::table('compartido_mensajes_salida')->where('id',$id)->update(['estado'=>'PROCESSING','bloqueado_por'=>(string)Str::uuid(),'bloqueado_hasta'=>now()->subSecond()]);
 $reclaimed=$processor->claim(1)[0];$processor->deliver($reclaimed);
 $deduplicated=DB::table('cache')->where('key',$key)->count()===1&&DB::table('compartido_bandeja_entrada')->where('id_evento',$id)->count()===1;
 DB::table('compartido_mensajes_salida')->where('id',$id)->update(['estado'=>'PENDING','intentos'=>4]);
 $failing=new App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor(['operations.probe.v1'=>['operations.failure'=>function(array $payload){throw new RuntimeException('SYNTHETIC_FAILURE');}]]);
 $failing->deliver($failing->claim(1)[0]);$failed=DB::table('compartido_mensajes_salida')->where('id',$id)->value('estado')==='FAILED';
 $processor->replay($id,null,'AlejandroEH56');$processor->deliver($processor->claim(1)[0]);
 $replayed=DB::table('compartido_mensajes_salida')->where('id',$id)->value('estado')==='PUBLISHED'&&DB::table('compartido_registros_auditoria')->where('id_sujeto',$id)->where('accion','OUTBOX_REPLAY')->count()===1;
 echo json_encode(['deduplicated'=>$deduplicated,'failed'=>$failed,'replayed'=>$replayed]);
}finally{DB::table('cache')->where('key',$key)->delete();DB::table('compartido_bandeja_entrada')->where('id_evento',$id)->delete();DB::table('compartido_mensajes_salida')->where('id',$id)->delete();}
PHP);
            $this->assertTrue($result['deduplicated']);
            $this->assertTrue($result['failed']);
            $this->assertTrue($result['replayed']);
        } finally {
            $this->composeOperation(['start', 'worker']);
        }
    }

    public function test_real_private_file_is_scanned_authorized_and_retained_as_archived_recovery_fixture(): void
    {
        $result = $this->php(<<<'PHP'
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
if(!app()->environment('staging')||DB::connection()->getDatabaseName()!=='servicios_moderno_stage'){throw new RuntimeException('ISOLATED_STAGE_REQUIRED');}
$actor=(string)Str::ulid();$path=tempnam('/tmp','private-file-');
DB::table('iam_identidades')->insert(['id'=>$actor,'nombre_mostrado'=>'STORAGE_RECOVERY_FIXTURE','correo_normalizado'=>null,'estado'=>'ACTIVA','creado_en'=>now(),'actualizado_en'=>now(),'version_autorizacion'=>1]);
try{
 $content='Harmless recovery fixture; no personal data.';file_put_contents($path,$content);
 $store=app(App\Modules\Shared\Infrastructure\Storage\PrivateFileStore::class);$id=$store->store($path,$actor);
 $read=$store->read($id,$actor);$hash=hash('sha256',$read['content']);
 try{$store->read($id,(string)Str::ulid());$denied=false;}catch(RuntimeException $e){$denied=true;}
 echo json_encode(['clean'=>$read['content']===$content,'unauthorizedDenied'=>$denied,'fileId'=>$id,'actorId'=>$actor,'sha256'=>$hash]);
}finally{unlink($path);DB::table('iam_identidades')->where('id',$actor)->update(['estado'=>'DESACTIVADA','version_autorizacion'=>2,'deshabilitado_en'=>now()]);}
PHP);
        $this->assertTrue($result['clean']);
        $this->assertTrue($result['unauthorizedDenied']);
        file_put_contents($this->root.'/artifacts/private-file-recovery-fixture.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function php(string $code): array
    {
        return json_decode($this->composeOperation(['exec', '-T', 'backend', 'php'], "<?php require 'vendor/autoload.php';\$app=require 'bootstrap/app.php';\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();".$code), true, flags: JSON_THROW_ON_ERROR);
    }

    private function sql(string $sql): void
    {
        $this->composeOperation(['exec', '-T', 'mysql', 'bash', '-c', 'export MYSQL_PWD="$(cat /run/secrets/mysql_root_password)"; exec mysql --user=root --database=servicios_moderno_stage'], $sql);
    }

    /** @param array<int,string> $args */
    private function process(array $args): Process
    {
        return new Process([$this->docker, 'compose', '--env-file', 'docker/environment.example', ...$args], $this->root, timeout: 60);
    }

    /** @param array<int,string> $args */
    private function composeOperation(array $args, ?string $input = null): string
    {
        $p = $this->process($args);
        $p->setInput($input);
        $p->run();
        $this->assertSame(0, $p->getExitCode(), 'Compose operation failed; inspect private operational logs.');

        return $p->getOutput();
    }

    private function httpStatus(string $path): int
    {
        $c = curl_init('https://localhost:8443'.$path);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CAINFO => $this->root.'/artifacts/docker-tls/root.crt']);
        try {
            $body = curl_exec($c);
            $this->assertNotFalse($body, 'Strict TLS request failed.');
            $this->assertDoesNotMatchRegularExpression('/SQLSTATE|password|PDOException|access_token|id_token/i', $body);

            return curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($c);
        }
    }
}
