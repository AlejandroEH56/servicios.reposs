<?php

namespace App\Modules\Residencies\Domain\Services;

use InvalidArgumentException;

final class EvidenceRules
{
    public const MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'];

    public static function originalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';

        return mb_substr(trim($name) ?: 'document', 0, 180);
    }

    public static function replaceable(string $status): bool
    {
        return in_array($status, ['ENVIADO', 'RECHAZADO'], true);
    }

    public static function decisionState(string $decision, ?string $observations): string
    {
        if (! in_array($decision, ['APROBADO', 'RECHAZADO', 'CAMBIOS_SOLICITADOS'], true)
            || ($decision !== 'APROBADO' && trim($observations ?? '') === '')) {
            throw new InvalidArgumentException('A rejection or change request requires observations.');
        }

        return $decision === 'APROBADO' ? 'APROBADO' : 'RECHAZADO';
    }

    public static function inForce(?string $from, ?string $until, string $today): bool
    {
        return ($from === null || $from <= $today) && ($until === null || $until >= $today);
    }
}
