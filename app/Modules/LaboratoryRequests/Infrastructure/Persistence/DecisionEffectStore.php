<?php

namespace App\Modules\LaboratoryRequests\Infrastructure\Persistence;

use App\Modules\LaboratoryRequests\Application\DecisionEffects;
use App\Modules\LaboratoryRequests\Infrastructure\Documents\ReceiptRenderer;
use App\Modules\Shared\Application\Ports\ScannedFiles;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DecisionEffectStore implements DecisionEffects
{
    public function __construct(private RequestAccess $access, private ScannedFiles $files, private ReceiptRenderer $renderer) {}

    public function deliver(array $event): void
    {
        $decision = DB::table('laboratorios_decisiones_solicitud')->where('id', $event['data']['decisionId'])->firstOrFail();
        if ($event['aggregateId'] !== $decision->id_solicitud || $event['actor'] !== $decision->id_identidad_decisor
            || ! hash_equals($decision->sha256_instantanea, hash('sha256', $decision->instantanea))) {
            throw new RuntimeException('DECISION_INTEGRITY_REJECTED');
        }
        $snapshot = json_decode($decision->instantanea, true, flags: JSON_THROW_ON_ERROR);
        if (! DB::table('laboratorios_comprobantes')->where('id_decision', $decision->id)->exists()) {
            $bytes = $this->renderer->pdf($snapshot);
            $path = tempnam(storage_path('framework/cache'), 'lab-receipt-');
            if ($path === false) {
                throw new RuntimeException('RECEIPT_STORAGE_UNAVAILABLE');
            }
            try {
                if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('RECEIPT_STORAGE_UNAVAILABLE');
                }
                $file = $this->files->store($path, $decision->id_identidad_decisor);
                $meta = $this->files->metadata($file);
                if ($meta === null || $meta['status'] !== 'LIMPIO' || $meta['mime'] !== 'application/pdf'
                    || $meta['sha256'] !== hash('sha256', $bytes) || $meta['size'] !== strlen($bytes)) {
                    throw new RuntimeException('RECEIPT_STORAGE_REJECTED');
                }
                DB::table('laboratorios_comprobantes')->insert(['id_decision' => $decision->id, 'id_archivo' => $file,
                    'sha256_archivo' => $meta['sha256'], 'tamano_archivo' => $meta['size'], 'emitido_en' => now()]);
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        DB::table('laboratorios_notificaciones')->insertOrIgnore(['id' => (string) Str::ulid(),
            'id_identidad_destinataria' => $snapshot['request']['ownerId'], 'id_solicitud' => $decision->id_solicitud,
            'id_decision' => $decision->id, 'estado_solicitud' => $decision->decision, 'creado_en' => now()]);
    }

    public function receipt(string $actor, string $request, bool $download = false): array
    {
        return DB::transaction(function () use ($actor, $request, $download): array {
            DB::table('laboratorios_controles')->where('codigo', 'WRITER')->sharedLock()->firstOrFail();
            $row = DB::table('laboratorios_solicitudes')->where('id', $request)->first() ?? abort(404);
            $this->access->view($actor, $row);
            $decision = DB::table('laboratorios_decisiones_solicitud')->where('id_solicitud', $request)->orderByDesc('version_solicitud')->first() ?? abort(404);
            $receipt = DB::table('laboratorios_comprobantes')->where('id_decision', $decision->id)->first();
            if (! $download) {
                return ['decisionId' => $decision->id, 'requestId' => $request, 'requestStatus' => $row->estado,
                    'status' => $receipt === null ? 'PENDIENTE' : 'LISTO', 'sha256' => $receipt?->sha256_archivo,
                    'size' => $receipt === null ? null : (int) $receipt->tamano_archivo];
            }
            abort_unless($receipt !== null && hash_equals($decision->sha256_instantanea, hash('sha256', $decision->instantanea)), 409);
            $meta = $this->files->metadata($receipt->id_archivo);
            abort_unless($meta !== null && $meta['ownerId'] === $decision->id_identidad_decisor && $meta['status'] === 'LIMPIO'
                && $meta['mime'] === 'application/pdf' && $meta['sha256'] === $receipt->sha256_archivo && $meta['size'] === (int) $receipt->tamano_archivo, 503);
            try {
                $file = $this->files->bytes($receipt->id_archivo);
            } catch (\Throwable) {
                abort(503, 'El comprobante no está disponible o no superó la verificación de integridad.');
            }
            abort_unless(hash_equals($receipt->sha256_archivo, hash('sha256', $file['content'])), 503);
            DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(),
                'id_identidad_actor' => $actor, 'nombre_contexto' => 'LaboratoryRequests', 'accion' => 'RECEIPT_DOWNLOADED',
                'tipo_sujeto' => 'LaboratoryRequest', 'id_sujeto' => $request, 'id_correlacion' => (string) Str::uuid()]);

            return ['content' => $file['content'], 'mime' => 'application/pdf', 'name' => 'comprobante-'.$decision->id.'.pdf'];
        }, 3);
    }

    public function notifications(string $actor, ?string $cursor = null): array
    {
        $after = null;
        if ($cursor !== null) {
            try {
                [$owner, $after] = array_pad(explode('|', Crypt::decryptString($cursor), 2), 2, '');
            } catch (DecryptException) {
                abort(422);
            }
            abort_unless($owner === $actor && Str::isUlid($after), 422);
        }
        $rows = DB::table('laboratorios_notificaciones')->where('id_identidad_destinataria', $actor)
            ->when($after !== null, fn (Builder $query) => $query->where('id', '<', $after))->orderByDesc('id')->limit(26)->get();
        $items = $rows->take(25);

        return ['items' => $items->map(fn (object $row): array => ['id' => $row->id, 'requestId' => $row->id_solicitud,
            'status' => $row->estado_solicitud, 'createdAt' => CarbonImmutable::parse($row->creado_en, 'UTC')->toISOString()])->all(),
            'nextCursor' => $rows->count() > 25 ? Crypt::encryptString($actor.'|'.$items->last()->id) : null];
    }
}
