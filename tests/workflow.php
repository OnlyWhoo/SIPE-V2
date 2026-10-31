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
foreach(['EGRESADO','ADMINISTRATIVO'] as $group){
    $person=CatalogService::save('estudiantes',['codigo_estudiante'=>'FLOW-'.$group.'-'.bin2hex(random_bytes(4)),'tipo_persona'=>$group,'nombres'=>'Persona sintética','apellidos'=>'de pruebas','programa_id'=>'','correo'=>''],$admin);
    $request=$in;$request['estudiante_id']=$person;$request['grupo_trabajo']=$group;$request['inicio']=date('Y-m-d\TH:i');$request['fin']=date('Y-m-d\TH:i',time()+3600);
    $pending=Lending::reserve($request,$employee['id']);
    test("$group requiere aprobación por solicitud",fn()=>expect(DB::one('SELECT estado FROM reservas WHERE id=?',[$pending])['estado']==='PENDIENTE_APROBACION'));
    test("$group empleado no puede aprobar",fn()=>Lending::approve($pending,'Aprobación sintética',$employee),true);
    test("$group no se entrega sin aprobar",fn()=>Lending::lend(array_replace($current,['estudiante_id'=>$person,'grupo_trabajo'=>$group]),$employee['id']),true);
    Lending::approve($pending,'Autorización manual sintética para esta solicitud',$admin);
    $delivered=Lending::lend(array_replace($current,['estudiante_id'=>$person,'grupo_trabajo'=>$group,'reserva_id'=>$pending,'fin'=>$request['fin']]),$employee['id']);
    test("$group entrega aprobada conserva grupo",fn()=>expect(Workflow::voucher($delivered)['grupo_trabajo']===$group));
    Lending::returnLoan(['prestamo_id'=>$delivered,'estado_fisico'=>'BUENO','apto'=>'1'],$employee['id']);
    test("$group aprobación no se reutiliza",fn()=>Lending::lend(array_replace($current,['estudiante_id'=>$person,'grupo_trabajo'=>$group]),$employee['id']),true);
}
test('empleado no registra administrativos',fn()=>CatalogService::save('estudiantes',['codigo_estudiante'=>'NO','tipo_persona'=>'ADMINISTRATIVO','nombres'=>'X','apellidos'=>'Y'],$employee),true);
test('grupo falsificado rechazado',fn()=>Lending::reserve(array_replace($in,['grupo_trabajo'=>'ADMINISTRATIVO']),$employee['id']),true);
test('reportes consultan todos los tipos disponibles',function(){foreach(Reports::LABELS as $key=>$label){expect(is_array(Reports::query(['report'=>$key])['rows']));}});
test('reporte fechas y estudiante',fn()=>expect(count(Reports::query(['report'=>'historial','identity'=>$student['codigo_estudiante'],'from'=>date('Y-m-d'),'to'=>date('Y-m-d'),'categoria'=>$category])['rows'])>0));
file_put_contents($root.'/var/workflow-fixture.json',json_encode(['resource'=>$resource,'reference'=>$code,'voucher'=>$loan,'student_code'=>$student['codigo_estudiante'],'student_qr'=>$student['qr_token'],'category'=>$category]));
echo "$count casos de flujo, $fail fallos. Base aislada: $database\n";$out=ob_get_clean();echo $out;file_put_contents($root.'/var/test-results/workflow.txt',$out);exit($fail?1:0);
