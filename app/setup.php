<?php
declare(strict_types=1);
use Sipe\Installation\Installer;
use Sipe\Middleware\Security;
$installer=new Installer($private,ROOT.'/database/install-plan.json');$error='';$pending=$installer->saved();
$parameters=$pending['parameters']??['host'=>'127.0.0.1','port'=>'3306','database'=>'sipe_v2','user'=>'root','password'=>''];
try {
    if($_SERVER['REQUEST_METHOD']==='POST'){
        Security::verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null);$parameters=Installer::parameters($_POST);
    }elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit('Método no permitido.');}
    $config=$installer->install($parameters,$base);session_regenerate_id(true);$_SESSION=[];redirect('login');
}catch(DomainException $ex){http_response_code(403);$error=$ex->getMessage();}
catch(PDOException $ex){$number=$ex->errorInfo[1]??0;$error=in_array($number,[1044,1045,1142,1227],true)?'La cuenta MySQL requiere credenciales válidas y permiso para crear una base nueva, tablas y vistas. Indica aquí la cuenta autorizada de tu servidor.':'No se pudo completar la preparación. Comprueba MySQL/MariaDB encendido, puerto, credenciales y permisos. La instalación propia podrá reanudarse de forma segura; no se borran bases.';}
catch(Throwable $ex){$error=$ex->getMessage();}
$title='Instalación de SIPE V2';$user=null;ob_start(); ?>
<section class="card"><h1>Preparar SIPE V2</h1><p>Edición DEMO local. La base, sus tablas, datos ficticios y administrador se preparan automáticamente. No necesitas importar SQL ni crear usuarios de la aplicación.</p>
<?php if($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif ?>
<p>Si tu XAMPP usa una cuenta o puerto especial, completa únicamente la conexión MySQL. Elige una base nueva <strong>sipe_v2</strong> o <strong>sipe_v2_otronombre</strong>. Nunca se utiliza la base original <strong>sipe</strong>.</p>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(Security::token()) ?>">
<?php foreach(['host'=>'Servidor local','port'=>'Puerto MySQL/MariaDB','database'=>'Base nueva','user'=>'Usuario MySQL','password'=>'Contraseña MySQL'] as $key=>$label): ?>
<label><?= e($label) ?><input name="<?= e($key) ?>" type="<?= $key==='password'?'password':'text' ?>" value="<?= e($key==='password'?'':($parameters[$key]??'')) ?>" <?= $key==='password'?'':'required' ?>></label>
<?php endforeach ?><button>Preparar y continuar</button></form>
<p>No se sobrescriben bases existentes ni se eliminan datos. Si aparece un error de permisos, un administrador debe autorizar la creación de una base nueva para esta cuenta. Para reanudar una instalación propia interrumpida, utiliza su mismo nombre y contraseña MySQL.</p></section>
<?php $content=ob_get_clean();require ROOT.'/app/Views/layout.php';
