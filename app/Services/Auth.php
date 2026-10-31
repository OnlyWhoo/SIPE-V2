<?php
declare(strict_types=1);
namespace Sipe\Services;
use Sipe\Models\Database as DB;
final class Auth
{
    public static function current(): ?array {
        if (empty($_SESSION['user_id'])) { return null; }
        if (time()-($_SESSION['last_seen'] ?? 0) > \config()['session_timeout']) { self::logout(); return null; }
        $user=DB::one('SELECT u.id,u.nombre_completo,u.correo,r.nombre AS rol FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=? AND u.activo=1',[$_SESSION['user_id']]);
        if (!$user || !in_array($user['rol'],['ADMINISTRADOR','EMPLEADO'],true)) { self::logout(); return null; }
        $_SESSION['last_seen']=time(); return $user;
    }
    public static function login(string $email, string $password): bool {
        $email=mb_strtolower(trim($email));
        $ip=substr($_SERVER['REMOTE_ADDR'] ?? 'CLI',0,45);
        // Límite persistente por dirección; no se evade cambiando la cookie de sesión.
        $attempts=DB::one("SELECT COUNT(*) n FROM auditoria WHERE accion='ACCESO_FALLIDO' AND ip_origen=? AND fecha_evento > DATE_SUB(NOW(),INTERVAL 15 MINUTE)",[$ip]);
        if ((int)$attempts['n']>=10) { throw new \DomainException('Demasiados intentos. Espera 15 minutos antes de volver a intentar.',429); }
        $u=DB::one('SELECT u.*,r.nombre AS rol FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.correo=?',[$email]);
        $hash=$u['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid=password_verify($password,$hash);
        if (!$valid || !$u || !(int)$u['activo'] || !in_array($u['rol'],['ADMINISTRADOR','EMPLEADO'],true)) { Audit::record('ACCESO_FALLIDO','usuarios',null,'Credenciales rechazadas'); return false; }
        DB::transaction(function() use($u): void { DB::run('UPDATE usuarios SET ultimo_acceso=NOW() WHERE id=?',[$u['id']]); Audit::record('INICIO_SESION','usuarios',(int)$u['id'],'', (int)$u['id']); });
        session_regenerate_id(true); $_SESSION=['user_id'=>(int)$u['id'],'last_seen'=>time(),'csrf'=>bin2hex(random_bytes(32))]; return true;
    }
    public static function logout(): void { $_SESSION=[]; if(session_status()===PHP_SESSION_ACTIVE){session_regenerate_id(true);} }
}
