<?php
declare(strict_types=1);
namespace Sipe\Installation;
use PDO;
use PDOException;
use RuntimeException;

final class Installer
{
    public const DEMO_EMAIL='profesor@sipe.local';
    public const DEMO_PASSWORD='Sipe2026!';
    private array $plan;
    private string $digest;
    public function __construct(private string $private,private string $planPath) {
        $text=file_get_contents($planPath);if($text===false){throw new RuntimeException('Falta el esquema de instalación.');}
        $this->plan=json_decode($text,true,512,JSON_THROW_ON_ERROR);$this->digest=hash('sha256',$text);
    }
    public static function parameters(array $input): array {
        foreach(['host','port','database','user','password'] as $key){if(isset($input[$key])&&!is_string($input[$key])){throw new RuntimeException('Formato de conexión inválido.');}}
        $host=$input['host']??'127.0.0.1';$port=$input['port']??'3306';$database=$input['database']??'sipe_v2';
        if(!in_array($host,['127.0.0.1','localhost','::1'],true)||!ctype_digit($port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Usa un servidor local y su puerto MySQL válido.');}
        if(!preg_match('/\Asipe_v2(?:_[a-zA-Z0-9]+)*\z/D',$database)||strlen($database)>64){throw new RuntimeException('Elige sipe_v2 o un nombre nuevo que comience por sipe_v2_. Nunca se utiliza la base sipe original.');}
        $user=$input['user']??'root';if($user===''||strlen($user)>80||strlen($input['password']??'')>1024){throw new RuntimeException('Revisa la cuenta MySQL.');}
        return ['host'=>$host,'port'=>$port,'database'=>$database,'user'=>$user,'password'=>$input['password']??''];
    }
    public static function connect(array $p): PDO {
        $pdo=new PDO('mysql:host='.$p['host'].';port='.$p['port'].';charset=utf8mb4',$p['user'],$p['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]);
        $version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
        if((stripos($version,'MariaDB')!==false&&version_compare($version,'10.4','<'))||(stripos($version,'MariaDB')===false&&version_compare($version,'8.0.16','<'))){throw new RuntimeException('Se requiere MariaDB 10.4+ o MySQL 8.0.16+ con CHECK efectivos. Solo se certifican las versiones documentadas.');}
        $pdo->exec("SET time_zone='-05:00'");$pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");return $pdo;
    }
    public function saved(): ?array {
        $path=$this->private.'/pending.json';if(!is_file($path)){return null;}
        return json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    }
    private function recordPath(array $p): string {return $this->private.'/ownership-'.hash('sha256',$p['host'].':'.$p['port'].'/'.$p['database']).'.json';}
    private function state(array $state): void {
        $text=json_encode($state,JSON_THROW_ON_ERROR);
        Local::write($this->recordPath($state['parameters']),$text);Local::write($this->private.'/pending.json',$text);
    }
    private function exists(PDO $pdo,string $db,string $table): bool {
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$q->execute([$db,$table]);return (int)$q->fetchColumn()>0;
    }
    private function checkpoint(string $name): void {
        // CLI-only fault injection for repeatable interrupted-installation tests.
        if(PHP_SAPI==='cli' && getenv('SIPE_V2_TEST_FAIL_AT')===$name){throw new RuntimeException('Synthetic interruption: '.$name);}
    }
    public function install(array $input,string $base): array {
        $p=self::parameters($input);$file=@fopen($this->private.'/installation.lock','c');
        if(!$file||!flock($file,LOCK_EX)){throw new RuntimeException('No se pudo bloquear la instalación local.');}
        $pdo=null;$locked=false;$lock='sipe-v2:'.substr(hash('sha256',$p['database']),0,48);
        try {
            $pdo=self::connect($p);$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);$locked=(int)$q->fetchColumn()===1;
            if(!$locked){throw new RuntimeException('Otra instalación está en curso. Vuelve a intentar.');}
            if(is_file($this->private.'/local.php')){throw new RuntimeException('La instalación ya está configurada. No se vuelve a ejecutar.');}
            $state=$this->saved();
            if($state && (($state['parameters']['database']??'')!==$p['database']||($state['parameters']['host']??'')!==$p['host']||($state['parameters']['port']??'')!==$p['port'])){$state=null;}
            if(!$state&&is_file($this->recordPath($p))){$state=json_decode((string)file_get_contents($this->recordPath($p)),true,512,JSON_THROW_ON_ERROR);}
            $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$p['database']]);$exists=(int)$q->fetchColumn()>0;
            if($exists && !$state){throw new RuntimeException('La base ya existe y no pertenece de forma verificable a esta instalación. No se modificó. Elige otro nombre nuevo sipe_v2_*.');}
            if(!$state){$state=['owner'=>bin2hex(random_bytes(32)),'plan'=>$this->digest,'parameters'=>$p];$this->state($state);}
            if(!hash_equals($state['plan']??'',$this->digest)){throw new RuntimeException('La versión pendiente no coincide. Conserva la carpeta privada y solicita revisión técnica.');}
            $state['parameters']=$p;$this->state($state);
            if(!$exists){
                try{$pdo->exec('CREATE DATABASE `'.$p['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
                catch(PDOException $e){if(in_array($e->errorInfo[1]??0,[1044,1045,1142,1227],true)){throw new RuntimeException('La cuenta MySQL no permite crear esta base nueva. Solicita al administrador autorización CREATE para ese nombre y permisos de esquema, vistas y operaciones de SIPE V2. No se usan bases desconocidas preexistentes.');}throw $e;}
                $pdo->exec('USE `'.$p['database'].'`');
                $pdo->exec('CREATE TABLE sipe_v2_installation(id TINYINT PRIMARY KEY,owner CHAR(64) NOT NULL,plan CHAR(64) NOT NULL,phase VARCHAR(20) NOT NULL,version VARCHAR(20) NOT NULL, CHECK(id=1)) ENGINE=InnoDB');
                $pdo->prepare("INSERT INTO sipe_v2_installation VALUES(1,?,?,'schema',?)")->execute([$state['owner'],$this->digest,$this->plan['version']]);
            }else{$pdo->exec('USE `'.$p['database'].'`');}
            if(!$this->exists($pdo,$p['database'],'sipe_v2_installation')){throw new RuntimeException('Base existente sin marcador verificable. No se reanudó. Elige otro nombre nuevo o solicita revisión técnica.');}
            $marker=$pdo->query('SELECT * FROM sipe_v2_installation WHERE id=1')->fetch();
            if(!$marker||!hash_equals($state['owner'],$marker['owner'])||!hash_equals($this->digest,$marker['plan'])){throw new RuntimeException('El propietario o esquema de la base no coincide. No se modificó.');}
            $this->checkpoint('owned');
            if(!$this->exists($pdo,$p['database'],'sipe_v2_steps')){$pdo->exec('CREATE TABLE sipe_v2_steps(id VARCHAR(100) PRIMARY KEY,checksum CHAR(64) NOT NULL) ENGINE=InnoDB');}
            foreach($this->plan['tables'] as $name=>$table){
                if(!$this->exists($pdo,$p['database'],$name)){$pdo->exec($table['sql']);$this->checkpoint('created:'.$name);}
                Schema::table($pdo,$p['database'],$name,$table['signature']);
                $checksum=hash('sha256',$table['sql']);$q=$pdo->prepare('SELECT checksum FROM sipe_v2_steps WHERE id=?');$q->execute([$name]);$done=$q->fetchColumn();
                if($done!==false&&!hash_equals($checksum,$done)){throw new RuntimeException('Paso de esquema divergente: '.$name);}
                if($done===false){$pdo->prepare('INSERT INTO sipe_v2_steps VALUES(?,?)')->execute([$name,$checksum]);}
            }
            $view=$this->plan['view'];if(!$this->exists($pdo,$p['database'],$view['name'])){$pdo->exec($view['sql']);}
            Schema::verify($pdo,$p['database'],$this->plan);$this->checkpoint('schema');
            if($marker['phase']==='schema'){
                $pdo->beginTransaction();
                try{
                    foreach(array_keys($this->plan['tables']) as $table){if((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==0){throw new RuntimeException('Una instalación pendiente contiene datos inesperados. No se sobrescriben.');}}
                    Demo::seed($pdo);$this->checkpoint('seed');
                    $pdo->exec("UPDATE sipe_v2_installation SET phase='ready' WHERE id=1");$pdo->commit();
                }catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
            }elseif($marker['phase']!=='ready'){throw new RuntimeException('Estado de instalación desconocido.');}
            $this->checkpoint('configured');
            $settings=['dsn'=>'mysql:host='.$p['host'].';port='.$p['port'].';dbname='.$p['database'].';charset=utf8mb4','user'=>$p['user'],'password'=>$p['password'],'base_path'=>$base,'secure_cookie'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','session_timeout'=>1800,'demo_local'=>true,'owner'=>$state['owner'],'plan'=>$this->digest];
            Local::write($this->private.'/local.php',"<?php\nreturn ".var_export($settings,true).";\n");
            return $settings;
        }finally{if($pdo&&$locked){$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}flock($file,LOCK_UN);fclose($file);}
    }
}
