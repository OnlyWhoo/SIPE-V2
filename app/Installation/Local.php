<?php
declare(strict_types=1);
namespace Sipe\Installation;
use RuntimeException;

final class Local
{
    public static function allowed(array $server): bool {
        if(!in_array($server['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){return false;}
        if(isset($server['HTTP_FORWARDED']) || isset($server['HTTP_X_FORWARDED_FOR']) || isset($server['HTTP_X_FORWARDED_HOST'])){return false;}
        $host=strtolower((string)($server['HTTP_HOST']??''));
        return (bool)preg_match('/\A(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]{1,5})?\z/D',$host);
    }
    public static function guard(): void {
        if(!self::allowed($_SERVER)){http_response_code(403);exit('SIPE V2 es una edición DEMO exclusiva de localhost, sin acceso remoto ni mediante proxies.');}
        $site=$_SERVER['HTTP_SEC_FETCH_SITE']??'none';
        if(!in_array($site,['none','same-origin'],true)){http_response_code(403);exit('Abre SIPE V2 directamente desde localhost.');}
        $origin=$_SERVER['HTTP_ORIGIN']??null;
        if($origin!==null){
            $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
            if(!hash_equals($scheme.'://'.($_SERVER['HTTP_HOST']??''),$origin)){http_response_code(403);exit('Origen no permitido.');}
        }
    }
    public static function protection(string $base): void {
        if(!ini_get('allow_url_fopen')){throw new RuntimeException('Habilita allow_url_fopen para comprobar la protección Apache; no se guardarán credenciales hasta verificarla.');}
        $port=(int)($_SERVER['SERVER_PORT']??0);
        if($port<1 || $port>65535){throw new RuntimeException('No se pudo identificar el puerto Apache.');}
        $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
        $path=implode('/',array_map('rawurlencode',explode('/',trim($base,'/'))));
        foreach(['app','config','database','var','vendor','docs','public'] as $directory){
            $url=$scheme.'://127.0.0.1:'.$port.($path!==''?'/'.$path:'').'/'.$directory.'/protection-check.txt';
            $context=stream_context_create(['http'=>['method'=>'GET','ignore_errors'=>true,'follow_location'=>0,'timeout'=>2,'header'=>"Connection: close\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
            $http_response_header=[];@file_get_contents($url,false,$context);
            if(!preg_match('/\AHTTP\/\S+ 403\b/',$http_response_header[0]??'')){throw new RuntimeException('Apache no protege '.$directory.'. Habilita mod_rewrite y AllowOverride All para esta carpeta. La instalación está bloqueada.');}
        }
    }
    public static function privateDirectory(): string {
        $document=realpath($_SERVER['DOCUMENT_ROOT']??dirname(ROOT));
        if($document===false){throw new RuntimeException('No se pudo resolver htdocs.');}
        $base=dirname($document).'/sipe-v2-private';
        $directory=$base.'/'.hash('sha256',strtolower(str_replace('\\','/',ROOT)));
        if(!is_dir($directory)&&!@mkdir($directory,0700,true)){throw new RuntimeException('Apache necesita escritura en la carpeta sipe-v2-private situada junto a htdocs.');}
        $resolved=strtolower(str_replace('\\','/',(string)realpath($directory))).'/';
        foreach([$document,ROOT] as $public){$prefix=strtolower(str_replace('\\','/',(string)realpath($public))).'/';if(str_starts_with($resolved,$prefix)){throw new RuntimeException('La carpeta privada debe estar fuera de htdocs.');}}
        return $directory;
    }
    public static function write(string $file,string $contents): void {
        $temp=$file.'.'.bin2hex(random_bytes(8)).'.tmp';
        $h=@fopen($temp,'x');if(!$h){throw new RuntimeException('No se pudo guardar un archivo privado.');}
        try{if(fwrite($h,$contents)!==strlen($contents)||!fflush($h)){throw new RuntimeException('Escritura privada incompleta.');}if(function_exists('fsync')){fsync($h);}}
        finally{fclose($h);}
        @chmod($temp,0600);
        if(!@rename($temp,$file)){@unlink($temp);throw new RuntimeException('No se pudo finalizar la configuración privada.');}
    }
}
