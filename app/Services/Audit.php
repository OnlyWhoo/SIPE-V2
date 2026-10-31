<?php
declare(strict_types=1);
namespace Sipe\Services;
use Sipe\Models\Database as DB;
final class Audit
{
    public static function record(string $action, string $entity, ?int $id = null, string $detail = '', ?int $user = null): void {
        DB::run('INSERT INTO auditoria(usuario_id,accion,entidad,entidad_id,detalle,ip_origen) VALUES(?,?,?,?,?,?)', [$user ?? ($_SESSION['user_id'] ?? null),$action,$entity,$id,$detail,substr($_SERVER['REMOTE_ADDR'] ?? 'CLI',0,45)]);
    }
}
