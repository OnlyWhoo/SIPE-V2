<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('SIPE_CONFIG='.$root.'/var/test-config.php');require $root.'/app/bootstrap.php';
use Sipe\Models\Database as DB;
use Sipe\Services\Lending;
if(($argv[1]??'')==='--worker'){
    $in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    while(microtime(true)<$in['barrier']){usleep(1000);}
    $actorId=(int)DB::one('SELECT id FROM usuarios WHERE correo=?',['admin@example.test'])['id'];
    try{if($in['mode']==='reserve'){Lending::reserve($in,$actorId);}else{Lending::lend($in,$actorId);}echo 'ACCEPTED';}
    catch(DomainException $e){echo 'REJECTED';}exit;
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
$text=implode(PHP_EOL,$results).PHP_EOL;echo $text;file_put_contents($root.'/var/test-results/concurrency.txt',$text);
