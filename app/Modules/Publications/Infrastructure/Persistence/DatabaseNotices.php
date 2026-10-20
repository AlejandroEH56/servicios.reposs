<?php

namespace App\Modules\Publications\Infrastructure\Persistence;

use App\Modules\Publications\Application\DTO\NoticeInput;
use App\Modules\Publications\Application\NoticeService;
use App\Modules\Publications\Application\Ports\NoticeRepository;
use App\Modules\Publications\Domain\Services\NoticeContent;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DatabaseNotices implements NoticeRepository
{
    public static function visible(Builder $query): Builder
    {
        $instant = now()->format('Y-m-d H:i:s.u');

        return $query->where('estado', 'PUBLICADO')->whereNotNull('publicado_en')->where('publicado_en', '<=', $instant)
            ->where(fn (Builder $q) => $q->whereNull('vence_en')->orWhere('vence_en', '>', $instant));
    }

    public function page(bool $public, array $query): array
    {
        $builder = DB::table('publicaciones_avisos')->whereNull('eliminado_en');
        if ($public) {
            self::visible($builder);
        }
        $filter = $query['filter'] ?? [];
        if (isset($filter['type'])) {
            $builder->where('tipo_aviso', $filter['type'] === 'RESIDENCIAS' ? 'RESIDENCIA' : $filter['type']);
        }
        if (! $public && isset($filter['status'])) {
            $builder->where('estado', $filter['status']);
        }
        if (($query['q'] ?? '') !== '') {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query['q']);
            $builder->whereRaw("(titulo LIKE ? ESCAPE '!' OR descripcion LIKE ? ESCAPE '!')", ['%'.$search.'%', '%'.$search.'%']);
        }
        $size = (int) ($query['page']['size'] ?? 25);
        $scope = hash('sha256', json_encode([$public, $filter, $query['q'] ?? '', $size], JSON_THROW_ON_ERROR));
        if (isset($query['page']['after'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($query['page']['after']), true, 8, JSON_THROW_ON_ERROR);
                abort_unless(is_array($cursor) && ($cursor['scope'] ?? '') === $scope && Str::isUlid($cursor['id'] ?? ''), 422);
                $builder->where('id', '<', $cursor['id']);
            } catch (Throwable) {
                abort(422);
            }
        }
        $rows = $builder->orderByDesc('id')->limit($size + 1)->get();
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size);
        $next = $hasMore ? Crypt::encryptString(json_encode(['scope' => $scope, 'id' => $rows->last()->id], JSON_THROW_ON_ERROR)) : null;
        $path = $public ? '/api/v1/public/notices' : '/api/v1/publications/notices';
        $nextQuery = $query;
        $nextQuery['page'] = ['size' => $size, 'after' => $next];

        return ['data' => $rows->map(fn (object $row) => $this->data($row, $public))->values()->all(),
            'page' => ['size' => $size, 'nextCursor' => $next, 'hasMore' => $hasMore],
            'links' => ['self' => $path.'?'.http_build_query($query), 'next' => $next === null ? null : $path.'?'.http_build_query($nextQuery)]];
    }

    public function get(string $id, bool $public): array
    {
        $query = DB::table('publicaciones_avisos')->where('id', $id)->whereNull('eliminado_en');
        if ($public) {
            self::visible($query);
        }
        $row = $query->first();
        abort_unless($row !== null, 404);

        return $this->data($row, $public);
    }

    public function command(string $action, ?string $id, ?NoticeInput $input, ?int $version, ?string $key, string $actor, string $correlation): array
    {
        return DB::transaction(function () use ($action, $id, $input, $version, $key, $actor, $correlation): array {
            app(NoticeService::class)->authorize($actor);
            $fingerprint = hash('sha256', json_encode([$action, $id, $input, $version], JSON_THROW_ON_ERROR));
            $scope = hash('sha256', $actor.'|'.$action.'|'.$id);
            if ($key !== null) {
                $cached = $this->idempotency($scope, $key, $fingerprint);
                if ($cached !== null) {
                    return $cached;
                }
            }
            $row = $id === null ? null : DB::table('publicaciones_avisos')->where('id', $id)->whereNull('eliminado_en')->lockForUpdate()->first();
            abort_if($id !== null && ! $row, 404);
            abort_if($row && (int) $row->version !== $version, 409);
            if ($action === 'create' || $action === 'update') {
                abort_unless($input !== null, 422);
                $values = ['tipo_aviso' => $input->type, 'titulo' => $input->title, 'descripcion' => $input->description,
                    'requisitos' => $input->requirements, 'url_externa' => $input->url, 'publicado_en' => $input->startsAt,
                    'vence_en' => $input->endsAt, 'actualizado_en' => now()];
                if ($row && $row->estado === 'PUBLICADO') {
                    NoticeContent::window($input->startsAt, $input->endsAt, true);
                }
                if ($action === 'create') {
                    $id = (string) Str::ulid();
                    DB::table('publicaciones_avisos')->insert($values + ['id' => $id, 'estado' => 'BORRADOR', 'version' => 1, 'creado_en' => now()]);
                } else {
                    DB::table('publicaciones_avisos')->where('id', $id)->update($values + ['version' => $version + 1]);
                }
                $this->image($id, $input->imageId, $actor);
            } else {
                abort_unless($row !== null, 404);
                if ($action === 'publish') {
                    abort_if($row->estado === 'PUBLICADO', 409);
                    NoticeContent::window($row->publicado_en, $row->vence_en, true);
                    abort_if($row->vence_en !== null && Carbon::parse($row->vence_en, 'UTC')->lte(now()), 422);
                } else {
                    abort_unless($action === 'withdraw' && $row->estado === 'PUBLICADO', 409);
                }
                DB::table('publicaciones_avisos')->where('id', $id)->update(['estado' => $action === 'publish' ? 'PUBLICADO' : 'RETIRADO',
                    'version' => $version + 1, 'id_identidad_publicador' => $actor, 'actualizado_en' => now()]);
            }
            $this->audit($id, $actor, 'NOTICE_'.strtoupper($action), $correlation);
            $result = $this->get($id, false);
            if ($key !== null) {
                DB::table('publicaciones_claves_idempotencia')->where('alcance', $scope)->where('clave', hash('sha256', $key))
                    ->update(['cuerpo_respuesta' => json_encode($result, JSON_THROW_ON_ERROR)]);
            }

            return $result;
        }, 3);
    }

    /** @return array<string, mixed>|null */
    private function idempotency(string $scope, string $key, string $fingerprint): ?array
    {
        DB::table('publicaciones_claves_idempotencia')->insertOrIgnore(['id' => (string) Str::ulid(), 'alcance' => $scope,
            'clave' => hash('sha256', $key), 'hash_solicitud' => $fingerprint, 'creado_en' => now(), 'expira_en' => now()->addDay()]);
        $row = DB::table('publicaciones_claves_idempotencia')->where('alcance', $scope)->where('clave', hash('sha256', $key))->lockForUpdate()->first();
        abort_unless($row && hash_equals($row->hash_solicitud, $fingerprint), 409);
        abort_if(Carbon::parse($row->expira_en, 'UTC')->lte(now()), 409);

        return $row->cuerpo_respuesta === null ? null : json_decode($row->cuerpo_respuesta, true, 32, JSON_THROW_ON_ERROR);
    }

    private function image(string $id, ?string $image, string $actor): void
    {
        $existing = DB::table('publicaciones_recursos_aviso')->where('id_aviso', $id)->where('activo', true)->value('id_archivo');
        if ($existing === $image) {
            return;
        }
        if ($image !== null) {
            $staged = DB::table('publicaciones_imagenes_preparadas')->where('id', $image)->where('id_identidad_carga', $actor)->lockForUpdate()->first();
            abort_unless($staged && Carbon::parse($staged->expira_en, 'UTC')->gt(now())
                && ! DB::table('publicaciones_recursos_aviso')->where('id_archivo', $image)->exists(), 409);
            abort_unless(DB::table('compartido_archivos_almacenados')->where('id', $image)->where('estado_escaneo', 'LIMPIO')->whereNull('eliminado_en')->exists(), 409);
        }
        DB::table('publicaciones_recursos_aviso')->where('id_aviso', $id)->where('activo', true)->update(['activo' => false]);
        if ($image !== null) {
            DB::table('publicaciones_recursos_aviso')->insert(['id_aviso' => $id, 'id_archivo' => $image, 'rol_recurso' => 'IMAGEN', 'orden_visualizacion' => 0, 'activo' => true]);
        }
    }

    /** @return array<string, mixed> */
    private function data(object $row, bool $public): array
    {
        $images = DB::table('publicaciones_recursos_aviso as r')->join('publicaciones_imagenes_preparadas as p', 'p.id', '=', 'r.id_archivo')
            ->join('compartido_archivos_almacenados as f', 'f.id', '=', 'p.id')->where('r.id_aviso', $row->id)->where('r.activo', true)
            ->where('f.estado_escaneo', 'LIMPIO')->whereNull('f.eliminado_en')->get(['p.id', 'p.ancho', 'p.alto']);

        return ['id' => $row->id, 'type' => $row->tipo_aviso === 'RESIDENCIA' ? 'RESIDENCIAS' : $row->tipo_aviso,
            'title' => $row->titulo, 'description' => $row->descripcion, 'requirements' => $row->requisitos ?? '', 'url' => $row->url_externa,
            'publicationWindow' => ['startsAt' => $this->iso($row->publicado_en), 'endsAt' => $this->iso($row->vence_en)],
            'status' => $row->estado, 'version' => (int) $row->version, 'createdAt' => $this->iso($row->creado_en),
            'resources' => $images->map(fn (object $file) => ['id' => $file->id, 'mime' => 'image/png', 'width' => (int) $file->ancho,
                'height' => (int) $file->alto, 'downloadUrl' => $public ? '/api/v1/public/notices/'.$row->id.'/images/'.$file->id : '/api/v1/files/'.$file->id.'/download'])->all()];
    }

    private function iso(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value, 'UTC')->toISOString();
    }

    private function audit(string $id, string $actor, string $action, string $correlation): void
    {
        DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actor,
            'nombre_contexto' => 'Publications', 'accion' => $action, 'tipo_sujeto' => 'Notice', 'id_sujeto' => $id, 'id_correlacion' => $correlation]);
    }
}
