<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Shared\Application\Ports\VirusScanner;
use App\Modules\Shared\Infrastructure\Storage\PrivateFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PrivateFileStoreTest extends TestCase
{
    use RefreshDatabase;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->source = tempnam(sys_get_temp_dir(), 'file-input-');
        file_put_contents($this->source, 'Harmless fixture for private storage.');
    }

    protected function tearDown(): void
    {
        unlink($this->source);
        parent::tearDown();
    }

    private function store(bool $clean = true, bool $available = true): PrivateFileStore
    {
        $scanner = new class($clean, $available) implements VirusScanner
        {
            public function __construct(private bool $clean, private bool $available) {}

            public function isClean(string $path): bool
            {
                if (! $this->available) {
                    throw new RuntimeException('private-scanner-details');
                }

                return $this->clean;
            }
        };
        $this->app->instance(VirusScanner::class, $scanner);

        return new PrivateFileStore($scanner);
    }

    public function test_clean_file_is_promoted_and_only_owner_can_download_with_integrity(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = $this->store();
        $id = $store->store($this->source, $owner->id);
        $this->assertSame(file_get_contents($this->source), $store->read($id, $owner->id)['content']);
        $this->assertDatabaseHas('compartido_archivos_almacenados', ['id' => $id, 'estado_escaneo' => 'LIMPIO', 'clave_objeto' => 'clean/'.$id]);
        Storage::disk('local')->assertMissing('quarantine/'.$id);
        $session = ['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => 1];
        $this->actingAs($owner)->withSession($session)->get('/files/'.$id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($other)->withSession($session)->get('/files/'.$id)->assertForbidden();
        Storage::disk('local')->put('clean/'.$id, 'tampered');
        $this->actingAs($owner)->withSession($session)->get('/files/'.$id)->assertForbidden();
    }

    public function test_infected_or_failed_scan_never_promotes_or_exposes_content(): void
    {
        $owner = User::factory()->create();
        $id = $this->store(false)->store($this->source, $owner->id);
        $this->assertDatabaseHas('compartido_archivos_almacenados', ['id' => $id, 'estado_escaneo' => 'INFECTADO']);
        Storage::disk('local')->assertMissing('clean/'.$id);
        try {
            $this->store(available: false)->store($this->source, $owner->id);
            $this->fail('Unavailable scanner must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('FILE_NOT_PROMOTED', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('compartido_archivos_almacenados')->where('estado_escaneo', 'LIMPIO')->count());
        $this->assertSame(1, DB::table('compartido_archivos_almacenados')->where('estado_escaneo', 'FALLIDO')->count());
        $this->expectException(RuntimeException::class);
        $this->store()->read($id, $owner->id);
    }

    public function test_empty_or_disallowed_file_is_rejected_before_scanning(): void
    {
        $owner = User::factory()->create();
        file_put_contents($this->source, '');
        $this->expectException(RuntimeException::class);
        $this->store()->store($this->source, $owner->id);
    }
}
