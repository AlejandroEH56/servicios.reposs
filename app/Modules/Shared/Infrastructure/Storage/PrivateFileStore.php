<?php

namespace App\Modules\Shared\Infrastructure\Storage;

use App\Models\User;
use App\Modules\IAM\Domain\IdentityState;
use App\Modules\Shared\Application\Ports\VirusScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PrivateFileStore
{
    public function __construct(private VirusScanner $scanner) {}

    public function store(string $source, string $actor): string
    {
        $this->active($actor);
        $size = is_file($source) ? filesize($source) : 0;
        $mime = is_file($source) ? (new \finfo(FILEINFO_MIME_TYPE))->file($source) : false;
        if (! $size || $size > config('modernization.storage.max_bytes')
            || ! in_array($mime, config('modernization.storage.allowed_mimes'), true)) {
            throw new RuntimeException('FILE_INPUT_REJECTED');
        }
        $id = (string) Str::ulid();
        $key = 'quarantine/'.$id;
        $hash = hash_file('sha256', $source);
        DB::table('compartido_archivos_almacenados')->insert([
            'id' => $id, 'disco' => 'local', 'clave_objeto' => $key, 'nombre_original' => basename($source),
            'tipo_mime' => $mime, 'tamano_bytes' => $size, 'sha256' => $hash, 'estado_escaneo' => 'PENDIENTE',
            'id_identidad_carga' => $actor, 'creado_en' => now(),
        ]);
        try {
            $stream = fopen($source, 'rb');
            try {
                if ($stream === false || ! Storage::disk('local')->put($key, $stream)) {
                    throw new RuntimeException('FILE_STORAGE_UNAVAILABLE');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $this->scanner->isClean(Storage::disk('local')->path($key))) {
                DB::table('compartido_archivos_almacenados')->where('id', $id)->update(['estado_escaneo' => 'INFECTADO']);
                $this->audit($id, $actor, 'FILE_REJECTED');

                return $id;
            }
            if (! hash_equals($hash, hash_file('sha256', Storage::disk('local')->path($key)))
                || ! Storage::disk('local')->move($key, 'clean/'.$id)) {
                throw new RuntimeException('FILE_INTEGRITY_REJECTED');
            }
            DB::table('compartido_archivos_almacenados')->where('id', $id)->update([
                'estado_escaneo' => 'LIMPIO', 'clave_objeto' => 'clean/'.$id,
            ]);
            $this->audit($id, $actor, 'FILE_STORED');
        } catch (Throwable) {
            DB::table('compartido_archivos_almacenados')->where('id', $id)->update(['estado_escaneo' => 'FALLIDO']);
            throw new RuntimeException('FILE_NOT_PROMOTED');
        }

        return $id;
    }

    /** @return array{content: string, mime: string} */
    public function read(string $id, string $actor): array
    {
        $this->active($actor);
        $row = DB::table('compartido_archivos_almacenados')->where('id', $id)->where('id_identidad_carga', $actor)
            ->where('estado_escaneo', 'LIMPIO')->whereNull('eliminado_en')->first();
        if (! $row || $row->clave_objeto !== 'clean/'.$id || $row->disco !== 'local') {
            throw new RuntimeException('FILE_ACCESS_DENIED');
        }
        $content = Storage::disk('local')->get($row->clave_objeto);
        if (! is_string($content) || strlen($content) !== (int) $row->tamano_bytes || ! hash_equals($row->sha256, hash('sha256', $content))) {
            throw new RuntimeException('FILE_INTEGRITY_REJECTED');
        }
        $this->audit($id, $actor, 'FILE_DOWNLOADED');

        return ['content' => $content, 'mime' => $row->tipo_mime];
    }

    private function active(string $actor): void
    {
        if (! Str::isUlid($actor) || ! User::query()->whereKey($actor)->where('estado', IdentityState::Active->value)->exists()) {
            throw new RuntimeException('FILE_ACCESS_DENIED');
        }
    }

    private function audit(string $file, string $actor, string $action): void
    {
        DB::table('compartido_registros_auditoria')->insert([
            'id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actor,
            'nombre_contexto' => 'Shared', 'accion' => $action, 'tipo_sujeto' => 'File', 'id_sujeto' => $file,
            'id_correlacion' => (string) Str::uuid(),
        ]);
    }
}
