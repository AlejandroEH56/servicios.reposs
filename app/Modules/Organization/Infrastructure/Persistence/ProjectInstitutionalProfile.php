<?php

namespace App\Modules\Organization\Infrastructure\Persistence;

use App\Modules\IAM\Application\IdentityDirectory;
use App\Modules\Organization\Application\ProfileProjector;
use App\Modules\Organization\Domain\Services\JobTitleParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectInstitutionalProfile implements ProfileProjector
{
    public function __construct(private IdentityDirectory $identities, private JobTitleParser $parser) {}

    public function handle(array $event): void
    {
        $identityId = $event['aggregateId'] ?? null;
        if (is_string($identityId) && Str::isUlid($identityId)) {
            $this->synchronize($identityId);
        }
    }

    public function synchronize(string $identityId): void
    {
        DB::transaction(function () use ($identityId): void {
            $profile = $this->identities->find($identityId);
            $employee = DB::table('organizacion_empleados')->where('id_identidad', $identityId)->lockForUpdate()->first();
            if ($profile === null || $employee === null || $employee->estado === 'ARCHIVADO'
                || $profile['status'] !== 'ACTIVA' || $profile['profileVersion'] <= $employee->version_perfil_microsoft) {
                return;
            }
            $titles = $this->parser->parse($profile['jobTitle']);
            $active = DB::table('organizacion_asignaciones_puesto')->where('id_empleado', $employee->id)
                ->where('origen_dato', 'MICROSOFT_JOBTITLE')->whereNull('fecha_fin')->get();
            $retained = [];
            foreach ($active as $position) {
                $index = array_search(mb_strtolower($position->nombre_puesto), array_map(mb_strtolower(...), $titles), true);
                if ($index === false || isset($retained[$index])) {
                    DB::table('organizacion_asignaciones_puesto')->where('id', $position->id)->update(['fecha_fin' => today()->toDateString(), 'actualizado_en' => now()]);
                } else {
                    $retained[$index] = true;
                    DB::table('organizacion_asignaciones_puesto')->where('id', $position->id)->update([
                        'nombre_puesto' => $titles[$index], 'orden_en_origen' => $index + 1,
                        'cadena_origen' => $profile['jobTitle'], 'actualizado_en' => now(),
                    ]);
                }
            }
            foreach ($titles as $index => $title) {
                if (! isset($retained[$index])) {
                    DB::table('organizacion_asignaciones_puesto')->insert([
                        'id' => (string) Str::ulid(), 'id_empleado' => $employee->id, 'id_unidad_organizacional' => null,
                        'nombre_puesto' => $title, 'origen_dato' => 'MICROSOFT_JOBTITLE',
                        'cadena_origen' => $profile['jobTitle'], 'orden_en_origen' => $index + 1,
                        'fecha_inicio' => today()->toDateString(), 'fecha_fin' => null,
                        'creado_en' => now(), 'actualizado_en' => now(),
                    ]);
                }
            }
            DB::table('organizacion_empleados')->where('id', $employee->id)->update([
                'correo_institucional' => $profile['email'], 'version_perfil_microsoft' => $profile['profileVersion'],
                'version' => $employee->version + 1, 'actualizado_en' => now(),
            ]);
            DB::table('compartido_registros_auditoria')->insert([
                'id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'nombre_contexto' => 'Organization',
                'accion' => 'MICROSOFT_PROFILE_SYNCHRONIZED', 'tipo_sujeto' => 'Employee', 'id_sujeto' => $employee->id,
                'metadatos' => json_encode(['profileVersion' => $profile['profileVersion'], 'activeMicrosoftPositions' => count($titles)], JSON_THROW_ON_ERROR),
            ]);
        }, 3);
    }
}
