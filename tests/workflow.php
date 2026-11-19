<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('SIPE_CONFIG='.$root.'/var/test-config.php');require $root.'/app/bootstrap.php';
use Sipe\Models\Database as DB;
use Sipe\Services\{Workflow,LoanPolicy,Lending,CatalogService,Reports};
$database=DB::one('SELECT DATABASE() n')['n'];if(!preg_match('/^sipe_test_[a-zA-Z0-9_]+$/D',$database)){throw new RuntimeException('Solo base de pruebas aislada.');}
$fixture=json_decode(file_get_contents($root.'/var/test-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$admin=['id'=>(int)DB::one('SELECT id FROM usuarios WHERE correo=?',[$fixture['correo']])['id'],'rol'=>'ADMINISTRADOR'];
$employee=['id'=>(int)DB::one('SELECT id FROM usuarios WHERE correo=?',[$fixture['empleado']])['id'],'rol'=>'EMPLEADO'];
$_SESSION['user_id']=$employee['id'];$_SERVER['REMOTE_ADDR']='127.0.0.3';$count=0;$fail=0;
function expect(bool $ok): void {if(!$ok){throw new RuntimeException('Resultado inesperado.');}}
function test(string $label,callable $work,bool $reject=false): void {global $count,$fail;$count++;try{$work();if($reject){throw new RuntimeException('Debió rechazar.');}echo "OK $label\n";}catch(DomainException $e){if($reject){echo "OK $label\n";}else{$fail++;echo "FAIL $label: {$e->getMessage()}\n";}}catch(Throwable $e){$fail++;echo "FAIL $label: {$e->getMessage()}\n";}}
ob_start();$student=Workflow::byId((int)$fixture['student']);
test('identificación manual por código',fn()=>expect(Workflow::student($student['codigo_estudiante'])['id']===$student['id']));
test('identificación por QR opaco',fn()=>expect(Workflow::student($student['qr_token'])['id']===$student['id']));
test('identificación desconocida rechazada',fn()=>Workflow::student('NO-EXISTE-'.bin2hex(random_bytes(5))),true);
test('tipo obligatorio',fn()=>LoanPolicy::options([]),true);
test('interno propone cierre a las 20:00',fn()=>expect(LoanPolicy::interval(['tipo_prestamo'=>'INTERNO'],'2099-05-01T09:00')[1]==='2099-05-01 20:00:00'));
test('interno después del cierre rechazado',fn()=>LoanPolicy::interval(['tipo_prestamo'=>'INTERNO'],'2099-05-01T20:00'),true);
test('interno no cambia de día',fn()=>LoanPolicy::interval(['tipo_prestamo'=>'INTERNO','fin'=>'2099-05-02T10:00'],'2099-05-01T09:00'),true);
test('interno respeta máximo configurado',fn()=>LoanPolicy::interval(['tipo_prestamo'=>'INTERNO','fin'=>'2099-05-01T20:01'],'2099-05-01T09:00'),true);
test('externo exige fecha y hora',fn()=>LoanPolicy::interval(['tipo_prestamo'=>'EXTERNO'],'2099-05-01T09:00'),true);
test('externo rechaza intervalo invertido',fn()=>LoanPolicy::interval(['tipo_prestamo'=>'EXTERNO','fin'=>'2099-05-01T08:00'],'2099-05-01T09:00'),true);
test('hora configurable en una fuente',function(){DB::run("UPDATE configuraciones SET valor='19:00' WHERE clave='hora_limite_interno'");try{expect(LoanPolicy::interval(['tipo_prestamo'=>'INTERNO'],'2099-05-01T09:00')[1]==='2099-05-01 19:00:00');}finally{DB::run("UPDATE configuraciones SET valor='20:00' WHERE clave='hora_limite_interno'");}});
$category=(int)DB::one('SELECT categoria_id FROM recursos WHERE id=?',[$fixture['resource']])['categoria_id'];
$code='FLOW-'.bin2hex(random_bytes(5));$resource=CatalogService::save('recursos',['codigo_inventario'=>$code,'nombre'=>'Recurso sintético de flujo','descripcion'=>'Pruebas aisladas','categoria_id'=>$category,'estado_fisico'=>'BUENO','estado_operativo'=>'DISPONIBLE'],$admin);
test('búsqueda por referencia',fn()=>expect(Workflow::movements(['q'=>$code,'estado'=>'TODAS'])['total']===0));
$in=['tipo_prestamo'=>'EXTERNO','grupo_trabajo'=>'ESTUDIANTE','estudiante_id'=>$student['id'],'recurso_id'=>$resource,'inicio'=>'2099-05-01T09:00','fin'=>'2099-05-01T11:00'];
test('vista previa no crea reserva ni entrega',function()use($in,$resource){Lending::preview($in);expect(!DB::one('SELECT id FROM reservas WHERE recurso_id=?',[$resource]));});
$reservation=Lending::reserve($in,$employee['id']);
test('reserva no marca entrega física',fn()=>expect(!DB::one('SELECT id FROM prestamos WHERE reserva_id=?',[$reservation])));
test('franjas derivadas de reserva real',function()use($resource){$slots=Workflow::availability($resource,'2099-05-01');expect(count($slots)===3 && $slots[1]['estado']==='Reservado' && $slots[1]['inicio']==='2099-05-01 09:00:00');});
test('otros días sin ocupación ficticia',fn()=>expect(Workflow::availability($resource,'2099-05-02')[0]['estado']==='Disponible'));
test('confirmación revalida solapamiento',fn()=>Lending::reserve($in,$employee['id']),true);
test('panel filtra por referencia real',fn()=>expect(Workflow::movements(['q'=>$code])['total']===1));
test('cancelación persiste y audita',function()use($reservation){Lending::cancel($reservation);expect(DB::one('SELECT estado FROM reservas WHERE id=?',[$reservation])['estado']==='CANCELADA');expect((bool)DB::one("SELECT id FROM auditoria WHERE accion='CANCELAR' AND entidad_id=?",[$reservation]));});
$current=['tipo_prestamo'=>'EXTERNO','grupo_trabajo'=>'ESTUDIANTE','estudiante_id'=>$student['id'],'recurso_id'=>$resource,'fin'=>date('Y-m-d\TH:i',time()+7200),'estado_fisico'=>'BUENO'];
$loan=Lending::lend($current,$employee['id']);
test('entrega efectiva con responsable y hora',fn()=>expect((int)DB::one('SELECT entregado_por FROM prestamos WHERE id=?',[$loan])['entregado_por']===$employee['id']));
test('préstamo activo ocupa hasta devolución real',function()use($resource){$slots=Workflow::availability($resource,'2099-05-02');expect(str_starts_with($slots[0]['estado'],'Prestado'));});
test('vale externo usa registro persistido',function()use($loan,$employee){$v=Workflow::voucher($loan);expect($v['tipo_prestamo']==='EXTERNO' && (int)$v['entregado_por']===$employee['id']);});
test('devolución efectiva actualiza inventario',function()use($loan,$resource,$employee){Lending::returnLoan(['prestamo_id'=>$loan,'estado_fisico'=>'BUENO','apto'=>'1'],$employee['id']);expect(DB::one('SELECT estado_operativo FROM recursos WHERE id=?',[$resource])['estado_operativo']==='DISPONIBLE');});
$otherCategory=CatalogService::save('categorias_recursos',['nombre'=>'Categoría sintética sin autorización'],$admin);
$otherResource=CatalogService::save('recursos',[
    'codigo_inventario'=>'FLOW-OTRO-'.$code,'nombre'=>'Recurso sintético sin autorización',
    'categoria_id'=>$otherCategory,'estado_fisico'=>'BUENO','estado_operativo'=>'DISPONIBLE',
],$admin);
$unapproved=array_replace($in,['recurso_id'=>$otherResource]);
test('estudiante no puede revisar otra categoría',fn()=>Lending::preview($unapproved),true);
test('estudiante no puede reservar otra categoría',fn()=>Lending::reserve($unapproved,$employee['id']),true);
test('estudiante no puede recibir otra categoría',fn()=>Lending::lend(array_replace($current,['recurso_id'=>$otherResource]),$employee['id']),true);

$inactiveProgram=CatalogService::save('programas_academicos',['codigo'=>'FLOW-DOC','nombre'=>'Programa descriptivo sintético'],$admin);
foreach(['EGRESADO','ADMINISTRATIVO','DOCENTE'] as $group){
    $person=CatalogService::save('estudiantes',[
        'codigo_estudiante'=>'FLOW-'.$group.'-'.bin2hex(random_bytes(4)),'tipo_persona'=>$group,
        'nombres'=>'Persona sintética','apellidos'=>'de pruebas',
        'programa_id'=>$group==='DOCENTE'?$inactiveProgram:'','correo'=>'',
    ],$admin);
    if($group==='DOCENTE'){
        DB::run('UPDATE programas_academicos SET activo=0 WHERE id=?',[$inactiveProgram]);
    }
    $request=array_replace($in,[
        'estudiante_id'=>$person,'grupo_trabajo'=>$group,'recurso_id'=>$otherResource,
        'inicio'=>date('Y-m-d\TH:i'),'fin'=>date('Y-m-d\TH:i',time()+3600),
    ]);
    $reservationId=Lending::reserve($request,$employee['id']);
    test("$group reserva confirmada sin aprobación individual",function()use($reservationId){
        $row=DB::one('SELECT estado,aprobado_por FROM reservas WHERE id=?',[$reservationId]);
        expect($row['estado']==='CONFIRMADA' && $row['aprobado_por']===null);
        expect((bool)DB::one("SELECT id FROM auditoria WHERE accion='RESERVAR' AND entidad_id=?",[$reservationId]));
    });
    $delivery=array_replace($current,[
        'estudiante_id'=>$person,'grupo_trabajo'=>$group,'recurso_id'=>$otherResource,'fin'=>$request['fin'],
    ]);
    test("$group reserva confirmada bloquea una entrega que no la vincula",fn()=>Lending::lend($delivery,$employee['id']),true);
    $delivered=Lending::lend($delivery+['reserva_id'=>$reservationId],$employee['id']);
    test("$group entrega vinculada conserva el grupo",fn()=>expect(Workflow::voucher($delivered)['grupo_trabajo']===$group));
    Lending::returnLoan(['prestamo_id'=>$delivered,'estado_fisico'=>'BUENO','apto'=>'1'],$employee['id']);
    $direct=Lending::lend($delivery,$employee['id']);
    test("$group puede recibir un préstamo directo",fn()=>expect(Workflow::voucher($direct)['reserva_id']===null));
    Lending::returnLoan(['prestamo_id'=>$direct,'estado_fisico'=>'BUENO','apto'=>'1'],$employee['id']);
}
test('ninguna operación nueva queda pendiente',fn()=>expect(Workflow::movements(['estado'=>'PENDIENTE_APROBACION'])['total']===0));

DB::run("INSERT INTO reservas(estudiante_id,recurso_id,creada_por,inicio,fin,tipo_prestamo,grupo_trabajo,estado)
    VALUES(?,?,?,?,?,'EXTERNO','ESTUDIANTE','PENDIENTE_APROBACION')",
    [$student['id'],$resource,$employee['id'],"2099-05-01 09:00:00","2099-05-01 11:00:00"]);
$legacy=(int)DB::get()->lastInsertId();
test('pendiente histórico visible en su filtro sin ocupar horario',function()use($legacy,$in){
    expect((int)Workflow::movements(['estado'=>'PENDIENTE_APROBACION'])['rows'][0]['id']===$legacy);
    Lending::preview($in);
});
test('pendiente histórico se cancela individualmente y se conserva',function()use($legacy,$in,$employee){
    Lending::cancel($legacy);
    $new=Lending::reserve($in,$employee['id']);
    expect(DB::one('SELECT estado FROM reservas WHERE id=?',[$legacy])['estado']==='CANCELADA');
    expect(DB::one('SELECT estado FROM reservas WHERE id=?',[$new])['estado']==='CONFIRMADA');
    expect((bool)DB::one("SELECT id FROM auditoria WHERE accion='CANCELAR' AND entidad_id=?",[$legacy]));
});
test('empleado no registra administrativos',fn()=>CatalogService::save('estudiantes',['codigo_estudiante'=>'NO','tipo_persona'=>'ADMINISTRATIVO','nombres'=>'X','apellidos'=>'Y'],$employee),true);
test('grupo falsificado rechazado',fn()=>Lending::reserve(array_replace($in,['grupo_trabajo'=>'ADMINISTRATIVO']),$employee['id']),true);
test('reportes consultan todos los tipos disponibles',function(){foreach(Reports::LABELS as $key=>$label){expect(is_array(Reports::query(['report'=>$key])['rows']));}});
test('reporte fechas y estudiante',fn()=>expect(count(Reports::query(['report'=>'historial','identity'=>$student['codigo_estudiante'],'from'=>date('Y-m-d'),'to'=>date('Y-m-d'),'categoria'=>$category])['rows'])>0));
file_put_contents($root.'/var/workflow-fixture.json',json_encode(['resource'=>$resource,'reference'=>$code,'voucher'=>$loan,'student_code'=>$student['codigo_estudiante'],'student_qr'=>$student['qr_token'],'category'=>$category]));
echo "$count casos de flujo, $fail fallos. Base aislada: $database\n";$out=ob_get_clean();echo $out;file_put_contents($root.'/var/test-results/workflow.txt',$out);exit($fail?1:0);
