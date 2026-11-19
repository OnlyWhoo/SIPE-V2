<?php
declare(strict_types=1);
use Sipe\Installation\Installer;
use Sipe\Installation\Entrega;
use Sipe\Middleware\Security;
$installer=new Installer($private,ROOT.'/database/entrega-plan.json');$error='';$pending=$installer->saved();
$parameters=$pending['parameters']??['host'=>'127.0.0.1','port'=>getenv('SIPE_DB_PORT')?:'3306','database'=>getenv('SIPE_DB_DATABASE')?:'sipe_v2','user'=>getenv('SIPE_DB_USER')?:'root','password'=>getenv('SIPE_DB_PASSWORD')?:''];
$adminFile=ROOT.'/database/arranque-privado.json';
$admin=is_file($adminFile)?Entrega::read($adminFile):null;
try {
    if($_SERVER['REQUEST_METHOD']==='POST'){
        Security::verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null);$parameters=Installer::parameters($_POST);
        if($admin===null){
            $password=$_POST['admin_password']??'';
            if(!is_string($password)){throw new DomainException('Introduce una contraseña válida.');}
            Security::password($password);
            $admin=['nombre'=>Security::text($_POST['admin_name']??'',150),
                'correo'=>Security::email(Security::text($_POST['admin_email']??'',180)),
                'hash'=>password_hash($password,PASSWORD_DEFAULT),'password'=>$password];
        }
    }elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit('Método no permitido.');}
    if($admin!==null){
        $config=$installer->install($parameters,$base,$admin);session_regenerate_id(true);$_SESSION=[];redirect('login');
    }
}catch(DomainException $ex){http_response_code(403);$error=$ex->getMessage();}
catch(PDOException $ex){$number=$ex->errorInfo[1]??0;error_log('SIPE V2 instalación: SQLSTATE '.$ex->getCode().' ('.$number.')');$error=in_array($number,[1044,1045,1142,1227],true)?'La cuenta MySQL requiere credenciales válidas y permiso para crear una base nueva, tablas y vistas. Indica aquí la cuenta autorizada de tu servidor.':'No se pudo completar la preparación. Comprueba MySQL/MariaDB encendido, puerto, credenciales y permisos. La instalación propia podrá reanudarse de forma segura; no se borran bases.';}
catch(Throwable $ex){$error=$ex->getMessage();}
$title='Instalación de SIPE V2';$user=null;ob_start(); ?>
<section class="card"><h1>Preparar SIPE V2</h1><p>SIPE V2 prepara automáticamente el esquema y los datos incluidos en esta entrega utilizando MariaDB/MySQL de XAMPP.</p>
<?php if($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif ?>
<p>Indica la conexión de XAMPP. Elige una base nueva <strong>sipe_v2</strong> o <strong>sipe_v2_otronombre</strong>.</p>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(Security::token()) ?>">
<?php foreach(['host'=>'Servidor local','port'=>'Puerto MySQL/MariaDB','database'=>'Base nueva','user'=>'Usuario MySQL','password'=>'Contraseña MySQL'] as $key=>$label): ?>
<label><?= e($label) ?><input name="<?= e($key) ?>" type="<?= $key==='password'?'password':'text' ?>" value="<?= e($key==='password'?'':($parameters[$key]??'')) ?>" <?= $key==='password'?'':'required' ?>></label>
<?php endforeach ?>
<?php if(!is_file($adminFile)): ?>
<label>Nombre del administrador de SIPE<input name="admin_name" maxlength="150" required></label>
<label>Correo del administrador de SIPE<input name="admin_email" type="email" maxlength="180" required></label>
<label>Contraseña del administrador de SIPE<input name="admin_password" type="password" minlength="15" maxlength="72" autocomplete="new-password" required></label>
<?php endif ?>
<button>Preparar y continuar</button></form>
<p>Para reanudar una instalación interrumpida, utiliza el mismo nombre de base y las mismas credenciales.</p></section>
<?php $content=ob_get_clean();require ROOT.'/app/Views/layout.php';
