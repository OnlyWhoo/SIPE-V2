<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
ini_set('display_errors', '0');
use Sipe\Models\Database as DB;
use Sipe\Middleware\Security;
use Sipe\Services\Audit;
$locked=false;
$exitCode=0;
try {
    require dirname(__DIR__).'/app/bootstrap.php';
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($input)) { throw new JsonException(); }
    $email=Security::email((string)($input['correo']??''));
    $name=Security::text($input['nombre']??'',150);
    $password=(string)($input['password']??'');
    Security::password($password);
    // GET_LOCK pertenece a la conexión y se mantiene hasta después del commit.
    $database=(string)DB::one('SELECT DATABASE() AS name')['name'];
    $lockName='sipe:initial-admin:'.substr(hash('sha256',$database),0,40);
    $locked=(int)(DB::one('SELECT GET_LOCK(?, 10) AS acquired',[$lockName])['acquired']??0)===1;
    if (!$locked) { throw new DomainException('Otra creación del administrador está en curso. Intenta de nuevo.'); }
    DB::transaction(function() use($email,$name,$password): void {
        $role=DB::one("SELECT id FROM roles WHERE nombre='ADMINISTRADOR'");
        if (!$role) { throw new DomainException('Falta el rol ADMINISTRADOR. Revisa la instalación del esquema.'); }
        if (DB::one('SELECT id FROM usuarios LIMIT 1')) { throw new DomainException('Ya existen usuarios. Usa la administración del sistema para gestionar las cuentas.'); }
        DB::run('INSERT INTO usuarios(rol_id,nombre_completo,correo,password_hash) VALUES(?,?,?,?)',[$role['id'],$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        $id=(int)DB::get()->lastInsertId();
        Audit::record('ADMINISTRADOR_INICIAL','usuarios',$id,'Creado desde consola local',$id);
    });
} catch (DomainException $e) {
    fwrite(STDERR,$e->getMessage()."\n"); $exitCode=1;
} catch (JsonException $e) {
    fwrite(STDERR,"Entrada inválida. Ejecuta scripts/create-admin.ps1 e introduce los datos solicitados.\n"); $exitCode=1;
} catch (Throwable $e) {
    fwrite(STDERR,"No se pudo crear el administrador. Revisa la conexión, el esquema y los permisos de la cuenta de SIPE.\n"); $exitCode=1;
} finally {
    if ($locked) {
        try { DB::one('SELECT RELEASE_LOCK(?) AS released',[$lockName]); }
        catch (Throwable $e) { fwrite(STDERR,"No se pudo confirmar la liberación del bloqueo; se liberará al cerrar la conexión.\n"); $exitCode=1; }
    }
}
if ($exitCode===0) { echo "Administrador creado sin contraseña predeterminada.\n"; }
exit($exitCode);
