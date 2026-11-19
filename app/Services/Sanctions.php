<?php
declare(strict_types=1);

namespace Sipe\Services;

use Sipe\Models\Database as DB;

final class Sanctions
{
    public const BLOCK_MESSAGE = 'El usuario tiene una sanción activa y no puede solicitar recursos hasta que sea resuelta';

    public static function typeLabel(string $type): string
    {
        return $type === 'DANIO' ? 'DAÑO' : $type;
    }

    public static function hasOpen(int $student, bool $currentRead = false): bool
    {
        $sql = "SELECT id FROM sanciones WHERE estudiante_id=? AND estado='ABIERTA' LIMIT 1";
        return DB::one($sql.($currentRead ? ' LOCK IN SHARE MODE' : ''), [$student]) !== null;
    }

    // Debe ejecutarse con la fila del prestatario bloqueada hasta el fin de la transacción.
    public static function requireClear(int $student): void
    {
        if (self::hasOpen($student, true)) {
            throw new \DomainException(self::BLOCK_MESSAGE);
        }
    }
}
