<?php

namespace App\Modules\Publications\Infrastructure\Storage;

use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\Publications\Application\Ports\NoticeImages;
use App\Modules\Shared\Application\Ports\ScannedFiles;
use App\Modules\Shared\Application\Ports\VirusScanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PrivateNoticeImages implements NoticeImages
{
    public function __construct(private ScannedFiles $files, private VirusScanner $scanner, private AuthorizationDirectory $authorization) {}

    public function stage(string $path, string $actor, string $key, string $correlation): array
    {
        abort_unless($this->authorization->allows($actor, 'publicaciones.administrar'), 403);
        abort_unless(is_file($path) && filesize($path) > 0 && filesize($path) <= 2 * 1024 * 1024, 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $dimensions = @getimagesize($path);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) && is_array($dimensions)
            && $dimensions['mime'] === $mime && $dimensions[0] <= 4096 && $dimensions[1] <= 4096
            && $dimensions[0] > 0 && $dimensions[1] > 0 && $dimensions[0] * $dimensions[1] <= 4_000_000, 422);
        abort_unless(extension_loaded('gd'), 503);
        $hash = hash_file('sha256', $path);
        $key = hash('sha256', $actor.'|'.$key);

        return DB::transaction(function () use ($actor, $path, $dimensions, $hash, $key, $correlation): array {
            DB::table('publicaciones_cargas_idempotentes')->insertOrIgnore(['id' => (string) Str::ulid(), 'clave' => $key, 'sha256' => $hash, 'creado_en' => now()]);
            $load = DB::table('publicaciones_cargas_idempotentes')->where('clave', $key)->lockForUpdate()->first();
            abort_unless($load && hash_equals($load->sha256, $hash), 409);
            if ($load->id_archivo !== null) {
                $old = DB::table('publicaciones_imagenes_preparadas')->where('id', $load->id_archivo)->first();
                abort_unless($old && Carbon::parse($old->expira_en, 'UTC')->gt(now()), 409);

                return $this->result($old);
            }
            try {
                $clean = $this->scanner->isClean($path);
            } catch (Throwable) {
                abort(503);
            }
            abort_unless($clean, 422);
            $image = @imagecreatefromstring(file_get_contents($path));
            abort_unless($image !== false, 422);
            $temporary = tempnam(sys_get_temp_dir(), 'notice-');
            abort_unless(is_string($temporary), 503);
            try {
                imagepalettetotruecolor($image);
                imagesavealpha($image, true);
                abort_unless(imagepng($image, $temporary, 6) && filesize($temporary) <= 10 * 1024 * 1024, 422);
                try {
                    $id = $this->files->store($temporary, $actor);
                } catch (RuntimeException) {
                    abort(503);
                }
                abort_unless(DB::table('compartido_archivos_almacenados')->where('id', $id)->where('estado_escaneo', 'LIMPIO')->exists(), 422);
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
            $row = ['id' => $id, 'id_identidad_carga' => $actor, 'ancho' => $dimensions[0], 'alto' => $dimensions[1], 'expira_en' => now()->addDay()->format('Y-m-d H:i:s.u'), 'creado_en' => now()];
            DB::table('publicaciones_imagenes_preparadas')->insert($row);
            DB::table('publicaciones_cargas_idempotentes')->where('clave', $key)->update(['id_archivo' => $id]);
            DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(),
                'id_identidad_actor' => $actor, 'nombre_contexto' => 'Publications', 'accion' => 'NOTICE_IMAGE_STAGED',
                'tipo_sujeto' => 'File', 'id_sujeto' => $id, 'id_correlacion' => $correlation]);

            return $this->result((object) $row);
        }, 3);
    }

    public function download(string $file, ?string $notice, ?string $actor): array
    {
        $image = DB::table('publicaciones_imagenes_preparadas')->where('id', $file)->first();
        abort_unless($image !== null, 404);
        $link = DB::table('publicaciones_recursos_aviso')->where('id_archivo', $file)->where('activo', true)->first();
        if ($notice !== null) {
            abort_unless(config('publications.public_enabled'), 404);
            abort_unless($link && $link->id_aviso === $notice, 404);
            $instant = now()->format('Y-m-d H:i:s.u');
            $public = DB::table('publicaciones_avisos')->where('id', $notice)->whereNull('eliminado_en')->where('estado', 'PUBLICADO')
                ->whereNotNull('publicado_en')->where('publicado_en', '<=', $instant)
                ->where(fn ($q) => $q->whereNull('vence_en')->orWhere('vence_en', '>', $instant))->exists();
            abort_unless($public, 404);
        } else {
            abort_unless($actor !== null && $this->authorization->allows($actor, 'publicaciones.administrar')
                && ($link !== null || ($image->id_identidad_carga === $actor && Carbon::parse($image->expira_en, 'UTC')->gt(now()))), 404);
        }
        try {
            $bytes = $this->files->bytes($file);
        } catch (Throwable) {
            abort(503);
        }
        abort_unless($bytes['mime'] === 'image/png', 503);

        return $bytes;
    }

    /** @return array<string, mixed> */
    private function result(object $row): array
    {
        return ['id' => $row->id, 'status' => 'LIMPIO', 'mime' => 'image/png', 'width' => (int) $row->ancho,
            'height' => (int) $row->alto, 'expiresAt' => Carbon::parse($row->expira_en, 'UTC')->toISOString()];
    }
}
