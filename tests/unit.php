<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Sipe\Middleware\Security as S;
use Sipe\Services\Lending;
$n=0;$fail=0;
function check(string $name,callable $work,bool $reject=false): void {global $n,$fail;$n++;try{$work();if($reject){throw new RuntimeException('No rechazó la entrada.');}echo "OK $name\n";}catch(DomainException $e){if($reject){echo "OK $name\n";}else{$fail++;echo "FAIL $name: {$e->getMessage()}\n";}}catch(Throwable $e){$fail++;echo "FAIL $name: {$e->getMessage()}\n";}}
check('correo válido',fn()=>S::email('PERSONAL@example.test'));
check('correo inválido',fn()=>S::email('sin-correo'),true);
check('contraseña corta',fn()=>S::password('corta'),true);
check('contraseña larga válida',fn()=>S::password('Una frase de prueba segura'));
check('límite bcrypt',fn()=>S::password(str_repeat('x',73)),true);
check('rol empleado no administra',fn()=>S::admin(['rol'=>'EMPLEADO']),true);
check('rol administrador',fn()=>S::admin(['rol'=>'ADMINISTRADOR']));
check('CSRF ausente',fn()=>S::verify(null),true);
$_SESSION=['csrf'=>bin2hex(random_bytes(32))];check('CSRF válido',fn()=>S::verify($_SESSION['csrf']));
check('CSRF incorrecto',fn()=>S::verify(str_repeat('a',64)),true);
check('fecha inexistente',fn()=>Lending::dates('2099-02-30T10:00','2099-03-01T11:00'),true);
check('intervalo invertido',fn()=>Lending::dates('2099-01-01T11:00','2099-01-01T10:00'),true);
check('intervalo válido',fn()=>Lending::dates('2099-01-01T10:00','2099-01-01T11:00'));
check('estado físico inválido',fn()=>Lending::physical('OTRO'),true);
check('identificador negativo',fn()=>S::id(-1),true);
check('entrada no escalar',fn()=>S::text([],10),true);
check('escape HTML',function(){if(e('<script>')!=='&lt;script&gt;'){throw new RuntimeException('Escape incorrecto');}});
echo "$n casos, $fail fallos\n";exit($fail?1:0);
