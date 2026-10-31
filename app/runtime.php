<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__));
date_default_timezone_set('America/Bogota');
spl_autoload_register(function(string $class): void {
    if(str_starts_with($class,'Sipe\\')){$file=ROOT.'/app/'.str_replace('\\','/',substr($class,5)).'.php';if(is_file($file)){require $file;}}
});
$config=require ROOT.'/config/example.php';
function config(): array {global $config;return $config;}
function e(mixed $value): string {return htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $route=''): string {return rtrim(config()['base_path'],'/').'/index.php?r='.rawurlencode($route);}
function redirect(string $route): never {header('Location: '.url($route),true,303);exit;}
function view(string $name,array $data=[]): void {extract($data,EXTR_SKIP);ob_start();require ROOT.'/app/Views/'.$name.'.php';$content=ob_get_clean();require ROOT.'/app/Views/layout.php';}
if(PHP_SAPI==='cli'){$file=getenv('SIPE_CONFIG');if($file&&is_file($file)){$config=require $file;}return;}
ini_set('display_errors','0');ini_set('log_errors','1');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');header('Cache-Control: no-store');
\Sipe\Installation\Local::guard();
try {
    if(PHP_VERSION_ID<80200){throw new RuntimeException('Se requiere PHP 8.2 o superior.');}
    foreach(['pdo_mysql','mbstring','xmlwriter','dom','session','hash'] as $extension){if(!extension_loaded($extension)){throw new RuntimeException('Habilita la extensión PHP '.$extension.' en tu XAMPP.');}}
    $base=rtrim($config['base_path'],'/');\Sipe\Installation\Local::protection($base);
    $private=\Sipe\Installation\Local::privateDirectory();ini_set('error_log',$private.'/php-error.log');
    $configFile=$private.'/local.php';if(is_file($configFile)){$config=require $configFile;$config['base_path']=$base;}
    $sessions=$private.'/sessions';if(!is_dir($sessions)&&!mkdir($sessions,0700,true)){throw new RuntimeException('No se pudo crear el almacenamiento privado de sesiones.');}
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
    session_name('SIPEV2_'.substr(hash('sha256',ROOT),0,12));session_save_path($sessions);
    session_set_cookie_params(['httponly'=>true,'secure'=>$config['secure_cookie'],'samesite'=>'Lax','path'=>$base.'/']);session_start();
    if(!is_file($configFile)){require ROOT.'/app/setup.php';exit;}
    if(($config['demo_local']??false)!==true){throw new RuntimeException('Esta edición solo admite modo DEMO local. Para uso real se requiere una edición y credenciales revisadas.');}
    $db=\Sipe\Models\Database::get();$marker=$db->query('SELECT owner,plan,phase FROM sipe_v2_installation WHERE id=1')->fetch();$digest=hash_file('sha256',ROOT.'/database/install-plan.json');
    if(!$marker||$marker['phase']!=='ready'||!hash_equals($config['owner']??'',$marker['owner'])||!hash_equals($config['plan']??'',$marker['plan'])||!hash_equals($digest,$marker['plan'])){throw new RuntimeException('El marcador de instalación no coincide. Conserva los datos y solicita revisión técnica.');}
    if(($_SESSION['schema_verified']??'')!==$digest){$plan=json_decode((string)file_get_contents(ROOT.'/database/install-plan.json'),true,512,JSON_THROW_ON_ERROR);\Sipe\Installation\Schema::verify($db,(string)$db->query('SELECT DATABASE()')->fetchColumn(),$plan);$_SESSION['schema_verified']=$digest;}
}catch(Throwable $error){
    http_response_code(503);$message=$error instanceof PDOException?'No se puede conectar a la base configurada. Inicia MySQL/MariaDB y verifica la configuración privada. No se reinstala ni se cambian datos.':$error->getMessage();
    if(isset($private)){error_log('SIPE V2 bootstrap: '.get_class($error));}
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>SIPE V2 — revisar instalación</title><h1>Revisar instalación de SIPE V2</h1><p>'.e($message).'</p><p>Consulta README.md. No borres bases ni la carpeta privada para intentar reinstalar.</p></html>';exit;
}
