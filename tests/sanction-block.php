<?php
declare(strict_types=1);

$root=dirname(__DIR__);
putenv('SIPE_CONFIG='.$root.'/var/test-config.php');
require $root.'/app/bootstrap.php';

use Sipe\Models\Database as DB;
use Sipe\Services\{Administration,CatalogService,Lending,Sanctions};

if(!preg_match('/\Asipe_test_[a-zA-Z0-9_]+\z/D',DB::one('SELECT DATABASE() n')['n'])){
    throw new RuntimeException('Solo base de pruebas aislada.');
}
$fixture=json_decode(file_get_contents($root.'/var/test-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$admin=['id'=>(int)DB::one('SELECT id FROM usuarios WHERE correo=?',[$fixture['correo']])['id'],'rol'=>'ADMINISTRADOR'];
$_SESSION['user_id']=$admin['id'];
$program=DB::one('SELECT programa_id FROM estudiantes WHERE id=?',[$fixture['student']])['programa_id'];
$category=DB::one('SELECT categoria_id FROM recursos WHERE id=?',[$fixture['resource']])['categoria_id'];

function check(bool $condition,string $label): void {
    if(!$condition){throw new RuntimeException($label);}
    echo "OK $label\n";
}
function blocked(callable $operation): bool {
    try { $operation(); } catch(DomainException $error) { return $error->getMessage()===Sanctions::BLOCK_MESSAGE; }
    return false;
}

foreach(['ESTUDIANTE'=>'DANIO','DOCENTE'=>'MORA','ADMINISTRATIVO'=>'OTRA'] as $role=>$type){
    $student=CatalogService::save('estudiantes',[
        'codigo_estudiante'=>'BLOCK-'.bin2hex(random_bytes(6)),'nombres'=>'Persona de','apellidos'=>'Pruebas',
        'programa_id'=>$role==='ESTUDIANTE'?$program:'','tipo_persona'=>$role,'correo'=>'',
    ],$admin);
    $resources=[];
    foreach(['reserva','entrega','bloqueo'] as $usage){
        DB::run('INSERT INTO recursos(categoria_id,codigo_inventario,nombre) VALUES(?,?,?)',
            [$category,'BLOCK-'.bin2hex(random_bytes(6)),'Recurso sintético de '.$usage]);
        $resources[$usage]=(int)DB::get()->lastInsertId();
    }
    $in=[
        'estudiante_id'=>$student,'recurso_id'=>$resources['reserva'],'grupo_trabajo'=>$role,
        'tipo_prestamo'=>'EXTERNO','inicio'=>date('Y-m-d\TH:i'),
        'fin'=>date('Y-m-d\TH:i',time()+7200),'estado_fisico'=>'BUENO',
    ];
    $reserved=Lending::reserve($in,$admin['id']);
    $existing=Lending::lend(array_replace($in,['recurso_id'=>$resources['entrega']]),$admin['id']);
    check(!Sanctions::hasOpen($student),"$role puede reservar y recibir al estar libre de sanciones");
    if($type==='MORA'){
        DB::run('UPDATE prestamos SET fecha_entrega=DATE_SUB(NOW(),INTERVAL 4 DAY),
            fecha_limite=DATE_SUB(NOW(),INTERVAL 2 DAY) WHERE id=?',[$existing]);
    }
    Administration::sanction([
        'estudiante_id'=>$student,'tipo'=>$type,'motivo'=>'Evidencia y reglamento de prueba',
        'prestamo_id'=>$type==='MORA'?$existing:'',
    ],$admin);
    $opened=DB::one('SELECT id,tipo FROM sanciones WHERE estudiante_id=? ORDER BY id DESC LIMIT 1',[$student]);
    $new=array_replace($in,['recurso_id'=>$resources['bloqueo']]);
    check($opened['tipo']===$type && Sanctions::hasOpen($student)
        && blocked(fn()=>Lending::preview($new))
        && blocked(fn()=>Lending::reserve($new,$admin['id']))
        && blocked(fn()=>Lending::lend($new,$admin['id'])),
        "$role con ".Sanctions::typeLabel($type)." abierta rechaza revisión, reserva y entrega directa");
    check(blocked(fn()=>Lending::lend($in+['reserva_id'=>$reserved],$admin['id'])),
        "$role no recibe la entrega de su reserva previa");
    Lending::returnLoan(['prestamo_id'=>$existing,'estado_fisico'=>'BUENO','apto'=>'1'],$admin['id']);
    check(DB::one('SELECT estado FROM prestamos WHERE id=?',[$existing])['estado']==='DEVUELTO'
        && DB::one('SELECT estado FROM reservas WHERE id=?',[$reserved])['estado']==='CONFIRMADA'
        && DB::one('SELECT activo FROM estudiantes WHERE id=?',[$student])['activo']===1
        && Sanctions::hasOpen($student),
        "$role conserva cuenta activa, reserva anterior e historial y puede devolver durante el bloqueo");
    check(!DB::one('SELECT id FROM reservas WHERE recurso_id=?',[$resources['bloqueo']])
        && !DB::one('SELECT id FROM prestamos WHERE recurso_id=?',[$resources['bloqueo']])
        && DB::one('SELECT estado_operativo FROM recursos WHERE id=?',[$resources['bloqueo']])['estado_operativo']==='DISPONIBLE',
        "$role bloqueado deja el inventario y los movimientos intactos");

    $second=null;
    if($role==='ESTUDIANTE'){
        Administration::sanction(['estudiante_id'=>$student,'tipo'=>'OTRA','motivo'=>'Segunda sanción de prueba'],$admin);
        $second=DB::one('SELECT MAX(id) id FROM sanciones WHERE estudiante_id=?',[$student])['id'];
        check((int)DB::one("SELECT COUNT(*) n FROM sanciones WHERE estudiante_id=? AND estado='ABIERTA'",[$student])['n']===2
            && blocked(fn()=>Lending::preview($new)), 'dos sanciones abiertas mantienen el bloqueo');
    }
    Administration::resolve(['id'=>$opened['id'],'estado'=>'RESUELTA','observaciones'=>'Resolución sintética'],$admin);
    if($second!==null){
        check(blocked(fn()=>Lending::reserve($new,$admin['id'])), 'resolver una de dos sanciones mantiene el bloqueo');
        Administration::resolve(['id'=>$second,'estado'=>'RESUELTA','observaciones'=>'Resolución sintética'],$admin);
    }
    $delivered=Lending::lend($in+['reserva_id'=>$reserved],$admin['id']);
    check(!Sanctions::hasOpen($student)
        && DB::one('SELECT reserva_id FROM prestamos WHERE id=?',[$delivered])['reserva_id']===$reserved,
        "$role recupera el acceso y puede recibir su reserva cuando se resuelve la última sanción");
    Lending::returnLoan(['prestamo_id'=>$delivered,'estado_fisico'=>'BUENO','apto'=>'1'],$admin['id']);
    $newReservation=Lending::reserve($new,$admin['id']);
    check(DB::one('SELECT estado FROM reservas WHERE id=?',[$newReservation])['estado']==='CONFIRMADA',
        "$role puede registrar nuevas reservas tras resolver sus sanciones");
}

check(Sanctions::typeLabel('DANIO')==='DAÑO','el tipo interno DANIO se presenta con Ñ');
