<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try {
    $route=$_GET['r']??'dashboard';
    if(!is_string($route)){throw new DomainException('Ruta inválida.',400);}
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){throw new DomainException('Método no permitido.',405);}
    (new Sipe\Controllers\Application())->handle($route);
} catch (DomainException $ex) {
    $code=in_array($ex->getCode(),[400,403,404,405,419,429],true)?$ex->getCode():422;http_response_code($code);
    view('error',['title'=>'Revisar solicitud','message'=>$ex->getMessage(),'user'=>null]);
} catch (PDOException $ex) {
    $duplicate=$ex->getCode()==='23000';http_response_code($duplicate?422:503);
    if(!$duplicate){error_log('Fallo de persistencia: '.get_class($ex).' código '.$ex->getCode());}
    view('error',['title'=>$duplicate?'Revisar datos':'Servicio no disponible','message'=>$duplicate?'El identificador ya existe o una relación no es válida. Revisa los datos.':'No se pudo completar la operación. Revisa la conexión y el estado de MySQL con el administrador.','user'=>null]);
} catch (Throwable $ex) {
    error_log(get_class($ex).': '.$ex->getMessage());http_response_code(503);
    view('error',['title'=>'Servicio no disponible','message'=>'No se pudo completar la operación. Consulta al administrador.','user'=>null]);
}
