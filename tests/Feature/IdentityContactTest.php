<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\IAM\Domain\IdentityState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class IdentityContactTest extends TestCase
{
    use RefreshDatabase;

    private string $proofPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->proofPath = tempnam(sys_get_temp_dir(), 'iam-proof-');
        config(['modernization.operations.enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->proofPath)) {
            unlink($this->proofPath);
        }
        parent::tearDown();
    }

    /** @return array{tenant: string, objectId: string, email: ?string, reason: string} */
    private function proof(User $user, ?string $email): array
    {
        $proof = ['tenant' => (string) Str::uuid(), 'objectId' => (string) Str::uuid(), 'email' => $email, 'reason' => 'USER_REQUEST'];
        DB::table('iam_cuentas_externas')->insert([
            'id' => (string) Str::ulid(), 'id_identidad' => $user->id, 'proveedor' => 'entra',
            'id_inquilino' => $proof['tenant'], 'sujeto_proveedor' => $proof['objectId'],
            'creado_en' => now(), 'actualizado_en' => now(),
        ]);
        file_put_contents($this->proofPath, json_encode($proof, JSON_THROW_ON_ERROR));

        return $proof;
    }

    public function test_contact_correction_preserves_link_and_state_and_audits_without_email(): void
    {
        $user = User::factory()->create(['estado' => IdentityState::Suspended]);
        $this->proof($user, ' Corrected@Example.test ');
        $this->artisan('iam:contact', ['identity' => $user->id, '--proof-file' => $this->proofPath])->assertSuccessful();
        $this->assertSame('corrected@example.test', $user->fresh()->correo_normalizado);
        $this->assertSame(2, $user->fresh()->version_autorizacion);
        $this->assertSame(IdentityState::Suspended, $user->fresh()->estado);
        $this->assertDatabaseCount('iam_cuentas_externas', 1);
        $audit = DB::table('compartido_registros_auditoria')->where('accion', 'IDENTITY_CONTACT_CORRECTED')->first();
        $this->assertSame($user->id, $audit->id_sujeto);
        $this->assertStringNotContainsString('example.test', $audit->metadatos);
    }

    public function test_collision_or_mismatched_link_cannot_change_contact_or_reassign_identity(): void
    {
        $user = User::factory()->create(['correo_normalizado' => 'first@example.test']);
        User::factory()->create(['correo_normalizado' => 'second@example.test']);
        $proof = $this->proof($user, 'SECOND@example.test');
        $this->artisan('iam:contact', ['identity' => $user->id, '--proof-file' => $this->proofPath])->assertFailed();
        $proof['email'] = 'free@example.test';
        $proof['objectId'] = (string) Str::uuid();
        file_put_contents($this->proofPath, json_encode($proof, JSON_THROW_ON_ERROR));
        $this->artisan('iam:contact', ['identity' => $user->id, '--proof-file' => $this->proofPath])->assertFailed();
        $this->assertSame('first@example.test', $user->fresh()->correo_normalizado);
        $this->assertSame(1, $user->fresh()->version_autorizacion);
        $this->assertDatabaseCount('iam_identidades', 2);
        $this->assertDatabaseCount('compartido_registros_auditoria', 0);
    }

    public function test_null_contact_is_allowed_and_runtime_cannot_correct_it(): void
    {
        $user = User::factory()->create();
        $this->proof($user, null);
        $this->artisan('iam:contact', ['identity' => $user->id, '--proof-file' => $this->proofPath])->assertSuccessful();
        $this->assertNull($user->fresh()->correo_normalizado);
        config(['modernization.operations.enabled' => false]);
        $this->expectException(RuntimeException::class);
        $this->artisan('iam:contact', ['identity' => $user->id, '--proof-file' => $this->proofPath]);
    }

    public function test_malformed_proof_is_rejected_without_logging_the_payload(): void
    {
        $user = User::factory()->create();
        file_put_contents($this->proofPath, '{secret=personal@example.test}');
        $this->artisan('iam:contact', ['identity' => $user->id, '--proof-file' => $this->proofPath])
            ->expectsOutputToContain('CONTACT_CORRECTION_REJECTED')->assertFailed();
        $this->assertSame(1, $user->fresh()->version_autorizacion);
    }
}
