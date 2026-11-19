<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('SIPE_CONFIG='.$root.'/var/test-config.php');require $root.'/app/bootstrap.php';
use Sipe\Models\Database as DB;
use Sipe\Services\{Administration,Lending,Sanctions};
$database=DB::one('SELECT DATABASE() n')['n'];
if(!preg_match('/\Asipe_test_[a-zA-Z0-9_]+\z/D',$database)){throw new RuntimeException('Solo base de pruebas aislada.');}
if(($argv[1]??'')==='--worker'){
    $in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    while(microtime(true)<$in['barrier']){usleep(1000);}
    $actorId=(int)DB::one('SELECT id FROM usuarios WHERE correo=?',['admin@example.test'])['id'];
    $_SESSION['user_id']=$actorId;
    if(!empty($in['connection'])){
        DB::get()->exec('SET innodb_lock_wait_timeout=12');
        echo DB::one('SELECT CONNECTION_ID() id')['id'], PHP_EOL;
        flush();
    }
    try{
        match($in['mode']){
            'reserve'=>Lending::reserve($in,$actorId),
            'lend'=>Lending::lend($in,$actorId),
            'sanction'=>Administration::sanction($in+['tipo'=>'DANIO','motivo'=>'Prueba de concurrencia'],
                                                ['id'=>$actorId,'rol'=>'ADMINISTRADOR']),
        };
        echo 'ACCEPTED';
    } catch(DomainException $e) {
        echo $e->getMessage()===Sanctions::BLOCK_MESSAGE?'BLOCKED':(!empty($in['connection'])?$e->getMessage():'REJECTED');
    }
    exit;
}
$fixture=json_decode(file_get_contents($root.'/var/test-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$category=DB::one('SELECT categoria_id FROM recursos WHERE id=?',[$fixture['resource']])['categoria_id'];$results=[];
foreach(['reserve','lend'] as $mode){
    DB::run("INSERT INTO recursos(categoria_id,codigo_inventario,nombre) VALUES(?,?,?)",[$category,'CONCURRENT-'.bin2hex(random_bytes(6)),'Recurso de concurrencia']);$resource=(int)DB::get()->lastInsertId();
    $in=['tipo_prestamo'=>'EXTERNO','mode'=>$mode,'barrier'=>microtime(true)+1,'estudiante_id'=>$fixture['student'],'recurso_id'=>$resource,'inicio'=>'2099-01-02T10:00','fin'=>$mode==='reserve'?'2099-01-02T11:00':date('Y-m-d\\TH:i',time()+7200),'estado_fisico'=>'BUENO'];
    $workers=[];for($i=0;$i<2;$i++){$p=proc_open([PHP_BINARY,__FILE__,'--worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);if(!is_resource($p)){throw new RuntimeException('No se pudo iniciar el proceso.');}fwrite($pipes[0],json_encode($in));fclose($pipes[0]);$workers[]=[$p,$pipes];}
    $out=[];foreach($workers as [$p,$pipes]){$out[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);if($exit!==0||$err){throw new RuntimeException('Falló worker: '.$err);}}
    sort($out);if($out!==['ACCEPTED','REJECTED']){throw new RuntimeException('Asignaciones incompatibles: '.json_encode($out));}
    $table=$mode==='reserve'?'reservas':'prestamos';if((int)DB::one("SELECT COUNT(*) n FROM $table WHERE recurso_id=?",[$resource])['n']!==1){throw new RuntimeException('Persistencia duplicada.');}
    $results[]="OK $mode: dos procesos, una aceptación y un rechazo";
}

function startRaceWorker(array $input): array {
    $process=proc_open([PHP_BINARY,__FILE__,'--worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('No se pudo iniciar el worker de sanciones.');}
    fwrite($pipes[0],json_encode($input+['connection'=>true,'barrier'=>0],JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $id=(int) fgets($pipes[1]);
    if($id<1){throw new RuntimeException('El worker no abrió su conexión.');}
    return [$process,$pipes,$id];
}
function readRaceWorker(array $worker): string {
    [$process,$pipes]=$worker;
    $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$error){throw new RuntimeException('Falló el worker: '.$error);}
    return $out;
}
function waitForQuery(PDO $monitor,int $connection,string $sql): void {
    // La espera de un registro puede aparecer en PROCESSLIST antes de verse en INNODB_TRX.
    $query=$monitor->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');
    $deadline=microtime(true)+4;
    $observed=0;
    do {
        $query->execute([$connection]);
        $observed=$query->fetchColumn()===$sql?$observed+1:0;
        if($observed>=3){return;}
        usleep(50000);
    } while(microtime(true)<$deadline);
    throw new RuntimeException('No se observó la espera de la consulta '.$sql);
}
function verifyRace(bool $condition,string $message): void {
    if(!$condition){throw new RuntimeException($message);}
}

$rootConnection=static fn(): PDO=>new PDO(config()['dsn'],getenv('SIPE_TEST_DB_USER')?:'root',
    getenv('SIPE_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$gate=$rootConnection();
$monitor=$rootConnection();
$actor=(int)DB::one('SELECT id FROM usuarios WHERE correo=?',[$fixture['correo']])['id'];
foreach([['sanction','reserve'],['sanction','lend'],['lend','sanction']] as [$firstMode,$secondMode]){
    DB::run("INSERT INTO estudiantes(codigo_estudiante,nombres,apellidos,tipo_persona,qr_token)
        VALUES(?,?,?,'ADMINISTRATIVO',?)",['RACE-'.bin2hex(random_bytes(6)),'Usuario','de prueba',bin2hex(random_bytes(32))]);
    $person=(int)DB::get()->lastInsertId();
    DB::run('INSERT INTO recursos(categoria_id,codigo_inventario,nombre) VALUES(?,?,?)',
        [$category,'RACE-'.bin2hex(random_bytes(6)),'Equipo de prueba concurrente']);
    $resource=(int)DB::get()->lastInsertId();
    $input=['tipo_prestamo'=>'EXTERNO','grupo_trabajo'=>'ADMINISTRATIVO','estudiante_id'=>$person,'recurso_id'=>$resource,
        'inicio'=>date('Y-m-d\TH:i'),'fin'=>date('Y-m-d\TH:i',time()+7200),'estado_fisico'=>'BUENO'];
    $gate->beginTransaction();
    try {
        // La FK del responsable detiene el alta de la sanción tras bloquear al prestatario.
        // En el otro orden, bloquear el recurso detiene el préstamo tras tomar ese mismo bloqueo.
        $hold=$gate->prepare($firstMode==='sanction'?'SELECT id FROM usuarios WHERE id=? FOR UPDATE':
            'SELECT id FROM recursos WHERE id=? FOR UPDATE');
        $hold->execute([$firstMode==='sanction'?$actor:$resource]);
        verifyRace((bool)$hold->fetchColumn(),'No se pudo localizar la fila que controla la carrera.');
        $first=startRaceWorker(['mode'=>$firstMode]+$input);
        waitForQuery($monitor,$first[2],$firstMode==='sanction'?
            'INSERT INTO sanciones(estudiante_id,prestamo_id,registrada_por,tipo,motivo) VALUES(?,?,?,?,?)':
            'SELECT * FROM recursos WHERE id=? FOR UPDATE');
        $second=startRaceWorker(['mode'=>$secondMode]+$input);
        waitForQuery($monitor,$second[2],$secondMode==='sanction'?
            'SELECT id FROM estudiantes WHERE id=? FOR UPDATE':'SELECT * FROM estudiantes WHERE id=? FOR UPDATE');
        $gate->commit();
    } finally {
        if($gate->inTransaction()){$gate->rollBack();}
    }
    verifyRace(readRaceWorker($first)==='ACCEPTED','Falló la primera operación concurrente.');
    verifyRace(readRaceWorker($second)===($firstMode==='sanction'?'BLOCKED':'ACCEPTED'),
        'Resultado inesperado de la segunda operación concurrente.');
    $sanction=DB::one("SELECT id FROM sanciones WHERE estudiante_id=? AND estado='ABIERTA'",[$person]);
    $loan=DB::one('SELECT id,estado FROM prestamos WHERE recurso_id=?',[$resource]);
    $stock=DB::one('SELECT estado_operativo FROM recursos WHERE id=?',[$resource])['estado_operativo'];
    verifyRace((bool)$sanction && !DB::one('SELECT id FROM reservas WHERE recurso_id=?',[$resource]),
        'Se alteraron las reservas o la sanción concurrente.');
    verifyRace($firstMode==='sanction'?$loan===null && $stock==='DISPONIBLE':
        $loan!==null && $loan['estado']==='ACTIVO' && $stock==='PRESTADO',
        'Préstamo o inventario inconsistente tras la carrera.');
    if($loan){
        $audit=DB::get()->prepare("SELECT accion FROM auditoria WHERE (accion='ENTREGAR' AND entidad_id=?)
            OR (accion='REGISTRAR_SANCION' AND entidad_id=?) ORDER BY id");
        $audit->execute([$loan['id'],$sanction['id']]);
        verifyRace($audit->fetchAll(PDO::FETCH_COLUMN)===['ENTREGAR','REGISTRAR_SANCION'],
            'La auditoría no conserva el orden de ambas operaciones.');
        Lending::returnLoan(['prestamo_id'=>$loan['id'],'estado_fisico'=>'BUENO','apto'=>'1'],$actor);
    }
    $results[]="OK sanciones $firstMode/$secondMode: orden observado en MariaDB y persistencia consistente";
}
$text=implode(PHP_EOL,$results).PHP_EOL;echo $text;file_put_contents($root.'/var/test-results/concurrency.txt',$text);
