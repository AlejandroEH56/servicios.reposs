<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Shared\Application\Ports\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SprintThreePublicationsTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private object $scannerFake;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->editor = User::factory()->create();
        $this->grant($this->editor);
        $this->login($this->editor);
        $this->scanner(true);
    }

    private function grant(User $user): void
    {
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $user->id,
            'id_rol' => DB::table('iam_roles')->where('codigo', 'ADMIN_PUBLICACIONES')->value('id'),
            'id_identidad_otorgante' => $user->id, 'otorgado_en' => now()]);
    }

    private function login(User $user): void
    {
        $this->actingAs($user->fresh())->withSession(['iam_authenticated_at' => now()->timestamp,
            'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => $user->fresh()->version_autorizacion]);
    }

    private function scanner(bool $clean, bool $available = true): void
    {
        if (! isset($this->scannerFake)) {
            $this->scannerFake = new class implements VirusScanner
            {
                public bool $clean = true;

                public bool $available = true;

                public function isClean(string $path): bool
                {
                    if (! $this->available) {
                        throw new RuntimeException('scanner-private-error');
                    }

                    return $this->clean;
                }
            };
            $this->app->instance(VirusScanner::class, $this->scannerFake);
        }
        $this->scannerFake->clean = $clean;
        $this->scannerFake->available = $available;
    }

    private function input(): array
    {
        return ['type' => 'RESIDENCIAS', 'title' => 'Aviso institucional', 'description' => 'Descripción sin HTML.',
            'requirements' => 'Requisito', 'url' => 'https://example.test/aviso',
            'publicationWindow' => ['startsAt' => now()->subMinute()->toISOString(), 'endsAt' => now()->addHour()->toISOString()]];
    }

    private function create(?array $input = null): array
    {
        return $this->postJson('/api/v1/publications/notices', $input ?? $this->input(), ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data');
    }

    private function transition(array $notice, string $action = 'publish'): array
    {
        return $this->postJson('/api/v1/publications/notices/'.$notice['id'].'/'.$action, [],
            ['If-Match' => '"'.$notice['version'].'"', 'Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
    }

    private function stage(): array
    {
        return $this->post('/api/v1/files/staged', ['file' => UploadedFile::fake()->image('fixture.jpg', 32, 24), 'purpose' => 'NOTICE_IMAGE'],
            ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
    }

    public function test_lifecycle_versions_idempotency_and_audit_are_consistent(): void
    {
        $input = $this->input();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $draft = $this->postJson('/api/v1/publications/notices', $input, $headers)->assertCreated()->json('data');
        $this->assertSame($draft, $this->postJson('/api/v1/publications/notices', $input, $headers)->assertCreated()->json('data'));
        $this->assertDatabaseCount('publicaciones_avisos', 1);
        $this->postJson('/api/v1/publications/notices', array_replace($input, ['title' => 'Otro']), $headers)->assertConflict();
        $this->getJson('/api/v1/public/notices/'.$draft['id'])->assertNotFound();
        $published = $this->transition($draft);
        $this->getJson('/api/v1/public/notices/'.$draft['id'])->assertOk()->assertJsonPath('data.status', 'PUBLICADO');
        $this->patchJson('/api/v1/publications/notices/'.$draft['id'], $input, ['If-Match' => '"1"'])->assertConflict();
        $withdrawn = $this->transition($published, 'withdraw');
        $this->assertSame(3, $withdrawn['version']);
        $this->getJson('/api/v1/public/notices/'.$draft['id'])->assertNotFound();
        $this->assertDatabaseHas('compartido_registros_auditoria', ['accion' => 'NOTICE_WITHDRAW', 'id_sujeto' => $draft['id']]);
    }

    public function test_window_applies_to_list_detail_and_images_at_exact_expiration(): void
    {
        $this->travelTo(now()->startOfSecond());
        $image = $this->stage();
        $input = $this->input();
        $input['imageId'] = $image['id'];
        $input['publicationWindow'] = ['startsAt' => now()->addHour()->toISOString(), 'endsAt' => now()->addHours(2)->toISOString()];
        $notice = $this->transition($this->create($input));
        $path = '/api/v1/public/notices/'.$notice['id'];
        $imagePath = $path.'/images/'.$image['id'];
        $this->getJson($path)->assertNotFound();
        $this->get($imagePath)->assertNotFound();
        $this->travel(1)->hours();
        $this->getJson($path)->assertOk();
        $this->get($imagePath)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->travel(1)->hours();
        $this->getJson($path)->assertNotFound();
        $this->get($imagePath)->assertNotFound();
        $this->getJson('/api/v1/public/notices')->assertJsonCount(0, 'data');
    }

    public function test_access_is_explicit_and_anonymous_queries_do_not_leak_drafts(): void
    {
        $notice = $this->create();
        $this->login(User::factory()->create());
        $this->getJson('/api/v1/publications/notices')->assertForbidden();
        $this->postJson('/api/v1/publications/notices', $this->input(), ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->post('/api/v1/files/staged', [])->assertForbidden();
        Auth::logout();
        $this->getJson('/api/v1/publications/notices')->assertUnauthorized();
        $this->getJson('/api/v1/public/notices')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/notices/'.$notice['id'])->assertNotFound();
    }

    public function test_html_urls_unknown_properties_preconditions_and_windows_are_rejected(): void
    {
        $input = $this->input();
        foreach ([['title', '<script>alert(1)</script>'], ['url', 'javascript:alert(1)'], ['status', 'PUBLICADO'], ['identityId', $this->editor->id]] as [$key, $value]) {
            $this->postJson('/api/v1/publications/notices', array_replace($input, [$key => $value]), ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->postJson('/api/v1/publications/notices', $input)->assertUnprocessable();
        $input['publicationWindow']['unknown'] = true;
        $this->postJson('/api/v1/publications/notices', $input, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $draft = $this->create();
        $this->patchJson('/api/v1/publications/notices/'.$draft['id'], $this->input())->assertUnprocessable();
        $input = $this->input();
        $input['publicationWindow']['endsAt'] = now()->subDay()->toISOString();
        $this->postJson('/api/v1/publications/notices', $input, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_images_are_normalized_owner_bound_single_use_and_withdrawn_immediately(): void
    {
        $image = $this->stage();
        $this->assertDatabaseHas('compartido_archivos_almacenados', ['id' => $image['id'], 'tipo_mime' => 'image/png', 'estado_escaneo' => 'LIMPIO']);
        $other = User::factory()->create();
        $this->grant($other);
        $this->login($other);
        $input = $this->input() + ['imageId' => $image['id']];
        $this->postJson('/api/v1/publications/notices', $input, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->get('/api/v1/files/'.$image['id'].'/download')->assertNotFound();
        $this->login($this->editor);
        $notice = $this->transition($this->create($input));
        $this->get('/api/v1/public/notices/'.$notice['id'].'/images/'.$image['id'])->assertOk();
        $this->postJson('/api/v1/publications/notices', $input, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->transition($notice, 'withdraw');
        $this->get('/api/v1/public/notices/'.$notice['id'].'/images/'.$image['id'])->assertNotFound();
    }

    public function test_malware_scanner_failures_svg_and_integrity_changes_fail_closed(): void
    {
        $this->scanner(false);
        $this->post('/api/v1/files/staged', ['file' => UploadedFile::fake()->image('bad.jpg'), 'purpose' => 'NOTICE_IMAGE'],
            ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->scanner(true, false);
        $this->post('/api/v1/files/staged', ['file' => UploadedFile::fake()->image('bad.jpg'), 'purpose' => 'NOTICE_IMAGE'],
            ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertStatus(503)->assertDontSee('scanner-private-error');
        $this->scanner(true);
        $this->post('/api/v1/files/staged', ['file' => UploadedFile::fake()->createWithContent('bad.png', '<svg onload="alert(1)"/>'), 'purpose' => 'NOTICE_IMAGE'],
            ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('publicaciones_imagenes_preparadas', 0);
        $image = $this->stage();
        $notice = $this->transition($this->create($this->input() + ['imageId' => $image['id']]));
        Storage::disk('local')->put('clean/'.$image['id'], 'tampered');
        $this->get('/api/v1/public/notices/'.$notice['id'].'/images/'.$image['id'])->assertStatus(503);
    }

    public function test_cursor_is_bounded_signed_and_bound_to_filters(): void
    {
        foreach (range(1, 3) as $index) {
            $this->transition($this->create());
        }
        $first = $this->getJson('/api/v1/public/notices?page[size]=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('page.hasMore', true);
        $cursor = $first->json('page.nextCursor');
        $next = $this->getJson('/api/v1/public/notices?page[size]=2&page[after]='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotContains($next->json('data.0.id'), array_column($first->json('data'), 'id'));
        $this->getJson('/api/v1/public/notices?page[size]=101')->assertUnprocessable();
        $this->getJson('/api/v1/public/notices?page[size]=2&page[after]=tampered')->assertUnprocessable();
        $this->getJson('/api/v1/public/notices?page[size]=2&filter[type]=EMPLEO&page[after]='.urlencode($cursor))->assertUnprocessable();
        $this->getJson('/api/v1/public/notices?filter[status]=BORRADOR')->assertUnprocessable();
        $this->artisan('modernization:verify-identifiers', ['--enable-module' => ['publications']])->assertSuccessful();
    }

    public function test_image_retries_are_idempotent_and_normalization_discards_trailing_payloads(): void
    {
        $source = UploadedFile::fake()->image('source.jpg', 20, 15);
        $content = file_get_contents($source->getRealPath()).'<script>untrusted_payload</script>';
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()];
        $upload = fn () => ['file' => UploadedFile::fake()->createWithContent('renamed.png', $content), 'purpose' => 'NOTICE_IMAGE'];
        $first = $this->post('/api/v1/files/staged', $upload(), $headers)->assertCreated()->json('data');
        $second = $this->post('/api/v1/files/staged', $upload(), $headers)->assertCreated()->json('data');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('publicaciones_imagenes_preparadas', 1);
        $this->assertStringNotContainsString('untrusted_payload', Storage::disk('local')->get('clean/'.$first['id']));
        $this->post('/api/v1/files/staged', ['file' => UploadedFile::fake()->image('different.jpg', 21, 15), 'purpose' => 'NOTICE_IMAGE'], $headers)->assertConflict();
        $this->post('/api/v1/files/staged', ['file' => UploadedFile::fake()->image('wide.png', 4097, 1), 'purpose' => 'NOTICE_IMAGE'],
            ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_publish_retry_has_one_effect_and_empty_or_expired_windows_cannot_publish(): void
    {
        $draft = $this->create();
        $path = '/api/v1/publications/notices/'.$draft['id'].'/publish';
        $headers = ['If-Match' => '"1"', 'Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson($path, [], $headers)->assertOk()->json('data');
        $this->assertSame($first, $this->postJson($path, [], $headers)->assertOk()->json('data'));
        $this->assertSame(1, DB::table('compartido_registros_auditoria')->where('accion', 'NOTICE_PUBLISH')->count());
        $input = $this->input();
        $input['publicationWindow'] = ['startsAt' => null, 'endsAt' => null];
        $empty = $this->create($input);
        $this->postJson('/api/v1/publications/notices/'.$empty['id'].'/publish', [],
            ['If-Match' => '"1"', 'Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $input['publicationWindow'] = ['startsAt' => now()->subHours(2)->toISOString(), 'endsAt' => now()->subHour()->toISOString()];
        $expired = $this->create($input);
        $this->postJson('/api/v1/publications/notices/'.$expired['id'].'/publish', [],
            ['If-Match' => '"1"', 'Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_public_cutover_switch_is_reversible_without_losing_notices_or_exposing_images(): void
    {
        $image = $this->stage();
        $notice = $this->transition($this->create($this->input() + ['imageId' => $image['id']]));
        config(['publications.public_enabled' => false]);
        $this->getJson('/api/v1/public/notices')->assertNotFound();
        $this->getJson('/api/v1/public/notices/'.$notice['id'])->assertNotFound();
        $this->get('/api/v1/public/notices/'.$notice['id'].'/images/'.$image['id'])->assertNotFound();
        $this->assertDatabaseHas('publicaciones_avisos', ['id' => $notice['id'], 'estado' => 'PUBLICADO']);
        config(['publications.public_enabled' => true]);
        $this->getJson('/api/v1/public/notices/'.$notice['id'])->assertOk();
        $this->get('/api/v1/public/notices/'.$notice['id'].'/images/'.$image['id'])->assertOk();
    }
}
