<?php

namespace App\Modules\Residencies\Domain\Services;

use InvalidArgumentException;

class DocumentRules
{
    public const TYPES = ['SOLICITUD_RESIDENCIA', 'CARTA_PRESENTACION_FORMAL', 'CARTA_PRESENTACION_INFORMAL',
        'FORMATO_REPORTE_PARCIAL', 'FORMATO_REPORTE_FINAL', 'CONSTANCIA_ASESORIA'];

    public const VIEWS = ['SOLICITUD_RESIDENCIA' => 'solicitud', 'CARTA_PRESENTACION_FORMAL' => 'carta-formal',
        'CARTA_PRESENTACION_INFORMAL' => 'carta-informal', 'FORMATO_REPORTE_PARCIAL' => 'evaluacion-parcial',
        'FORMATO_REPORTE_FINAL' => 'evaluacion-final', 'CONSTANCIA_ASESORIA' => 'constancia'];

    /** @param array<array-key,mixed> $value */
    public static function canonical(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function folio(int $year, int $semester, int $number): string
    {
        if ($year < 2000 || $year > 2199 || ! in_array($semester, [1, 2], true) || $number < 1) {
            throw new InvalidArgumentException('INVALID_CERTIFICATE_SEQUENCE');
        }

        return 'ITSH/RPSS/'.$year.'('.$semester.')/'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /** @return array{string,string} */
    public static function semester(int $year, int $semester): array
    {
        self::folio($year, $semester, 1);

        return $semester === 1 ? [$year.'-02-01', $year.'-07-31'] : [$year.'-08-01', $year.'-12-31'];
    }

    public static function month(int $month): string
    {
        return [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
            7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'][$month];
    }

    /** @return list<string> */
    public static function logos(string $type): array
    {
        if ($type === 'CONSTANCIA_ASESORIA') {
            return array_map(fn (string $name): string => 'constancia-asesores/'.$name, ['p1_educacion.png', 'p2_tecnm.png', 'p3_gob_ver.png', 'p4_sev_edu.png', 'p5_det_ver.png', 'p5_logo_itsh_1.png']);
        }
        if (str_starts_with($type, 'CARTA_')) {
            return ['carta-presentacion/p1_logo_tec_itsh.png'];
        }

        return array_map(fn (string $name): string => 'solicitud-residencias/'.$name, ['p1_gob_ver.png', 'p2_sem_sys.png', 'p3_sev_edu.png', 'p4_det_ver.png', 'p5_logo_itsh.png']);
    }
}
