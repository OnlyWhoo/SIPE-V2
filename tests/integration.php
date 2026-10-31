<?php
declare(strict_types=1);
// Creates only synthetic sipe_test_* databases in the dedicated localhost lab.
$root=dirname(__DIR__);$admin=require $root.'/config/admin.local.php';
if(!str_contains($admin['dsn'],'host=127.0.0.1;port=3312;')){throw new RuntimeException('Use only the isolated localhost lab on 3312.');}
$pdo=new PDO($admin['dsn'],$admin['user'],$admin['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='sipe_test_'.date('YmdHis').'_'.bin2hex(random_bytes(3));
$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE `'.$name.'`');$pdo->exec(file_get_contents($root.'/database/schema.sql'));$pdo->exec("INSERT INTO roles(id,nombre) VALUES(1,'ADMINISTRADOR'),(2,'EMPLEADO'); INSERT INTO configuraciones(clave,valor) VALUES('hora_limite_interno','20:00')");
// Reproducir los permisos de install-local.php; root solo prepara el esquema.
$testUser='sipe_test_'.bin2hex(random_bytes(6));$testPassword=bin2hex(random_bytes(32));
$account=$pdo->quote($testUser)."@'127.0.0.1'";
$pdo->exec("CREATE USER $account IDENTIFIED BY ".$pdo->quote($testPassword));
$pdo->exec("GRANT SELECT ON $name.* TO $account");
foreach(['usuarios','programas_academicos','estudiantes','categorias_recursos','recursos','autorizaciones_programa_categoria','reservas','prestamos','devoluciones','inspecciones','sanciones','configuraciones'] as $table){$pdo->exec("GRANT INSERT, UPDATE ON $name.$table TO $account");}
$pdo->exec("GRANT INSERT ON $name.auditoria TO $account");
$pdo->exec("GRANT DELETE ON $name.autorizaciones_programa_categoria TO $account");
$config=require $root.'/config/example.php';$config['dsn']='mysql:host=127.0.0.1;port=3312;dbname='.$name.';charset=utf8mb4';$config['user']=$testUser;$config['password']=$testPassword;
file_put_contents($root.'/var/test-config.php',"<?php\nreturn ".var_export($config,true).";\n");putenv('SIPE_CONFIG='.$root.'/var/test-config.php');
require $root.'/app/bootstrap.php';
use Sipe\Models\Database as DB;
use Sipe\Services\{Auth,CatalogService,Lending,Administration};
$fail=0;$count=0;ob_start();session_save_path($root.'/var/sessions');session_start();$_SERVER['REMOTE_ADDR']='127.0.0.2';
function test(string $label,callable $fn,bool $reject=false): void {global $fail,$count;$count++;try{$fn();if($reject){throw new RuntimeException('Debió rechazar.');}echo "OK $label\n";}catch(DomainException $e){if($reject){echo "OK $label\n";}else{$fail++;echo "FAIL $label: {$e->getMessage()}\n";}}catch(Throwable $e){$fail++;echo "FAIL $label: {$e->getMessage()}\n";}}
function expect(bool $ok): void {if(!$ok){throw new RuntimeException('Resultado inesperado.');}}
$password=bin2hex(random_bytes(24));
$adminPayload=['nombre'=>'Administrador de prueba','correo'=>'admin@example.test','password'=>$password];
$startAdmin=function(string $payload)use($root): array{
    $p=proc_open([PHP_BINARY,$root.'/scripts/create-admin.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if(!is_resource($p)){throw new RuntimeException('No se pudo iniciar CLI.');}
    fwrite($pipes[0],$payload);fclose($pipes[0]);return [$p,$pipes];
};
$finishAdmin=function(array $worker): array{
    [$p,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
    expect(!preg_match('/SQLSTATE|Stack trace|PDOException|'.preg_quote($GLOBALS['testPassword'],'/').'/', $out.$err));
    return ['code'=>$code,'out'=>$out,'err'=>$err];
};
$runAdmin=fn(): array=>$finishAdmin($startAdmin(json_encode($adminPayload)));
$lockName='sipe:initial-admin:'.substr(hash('sha256',$name),0,40);
test('roles conserva privilegios mínimos',function(){
    foreach(['UPDATE roles SET nombre=nombre WHERE 1=0','DELETE FROM roles WHERE 1=0','LOCK TABLES roles WRITE'] as $sql){
        try{DB::run($sql);}catch(PDOException $e){expect($e->getCode()==='42000');continue;}
        throw new RuntimeException('Permiso excesivo sobre roles.');
    }
});
test('entrada inválida sin trazas',function()use($startAdmin,$finishAdmin){$r=$finishAdmin($startAdmin('{'));expect($r['code']!==0 && str_contains($r['err'],'Entrada inválida'));});
test('bloqueo ocupado informa espera agotada sin crear usuarios',function()use($pdo,$runAdmin,$lockName){
    $q=$pdo->prepare('SELECT GET_LOCK(?, 0)');$q->execute([$lockName]);expect((int)$q->fetchColumn()===1);
    try{$r=$runAdmin();expect($r['code']!==0 && str_contains($r['err'],'en curso'));expect((int)DB::one('SELECT COUNT(*) n FROM usuarios')['n']===0);}
    finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}
});
test('auditoría fallida revierte usuario y libera bloqueo',function()use($pdo,$runAdmin,$lockName){
    $pdo->exec("CREATE TRIGGER fail_initial_audit BEFORE INSERT ON auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");
    try{$r=$runAdmin();expect($r['code']!==0);expect((int)DB::one('SELECT COUNT(*) n FROM usuarios')['n']===0);expect((int)DB::one('SELECT IS_FREE_LOCK(?) n',[$lockName])['n']===1);}
    finally{$pdo->exec('DROP TRIGGER fail_initial_audit');}
});
test('primer administrador concurrente con permisos limitados',function()use($pdo,$startAdmin,$finishAdmin,$adminPayload,$lockName){
    $q=$pdo->prepare('SELECT GET_LOCK(?, 0)');$q->execute([$lockName]);expect((int)$q->fetchColumn()===1);
    $workers=[];
    try{
        $workers[]=$startAdmin(json_encode($adminPayload));
        $workers[]=$startAdmin(json_encode(array_replace($adminPayload,['correo'=>'otro@example.test'])));
        $deadline=microtime(true)+8;$waiting=0;
        do{$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.processlist WHERE USER=? AND INFO LIKE '%GET_LOCK%'");$q->execute([$GLOBALS['testUser']]);$waiting=(int)$q->fetchColumn();if($waiting===2){break;}usleep(10000);}while(microtime(true)<$deadline);
        expect($waiting===2);expect((int)DB::one('SELECT COUNT(*) n FROM usuarios')['n']===0);
    }finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}
    $results=array_map($finishAdmin,$workers);$codes=array_column($results,'code');sort($codes);expect($codes===[0,1]);
    expect((int)DB::one('SELECT COUNT(*) n FROM usuarios')['n']===1);
    expect((int)DB::one("SELECT COUNT(*) n FROM auditoria WHERE accion='ADMINISTRADOR_INICIAL'")['n']===1);
    expect((int)DB::one('SELECT IS_FREE_LOCK(?) n',[$lockName])['n']===1);
    DB::run('UPDATE usuarios SET correo=?',['admin@example.test']);
});
test('segundo arranque CLI no crea otra cuenta',function()use($runAdmin){$r=$runAdmin();expect($r['code']!==0 && str_contains($r['err'],'Ya existen usuarios'));expect((int)DB::one('SELECT COUNT(*) n FROM usuarios')['n']===1);});
$adminUser=['id'=>(int)DB::one('SELECT id FROM usuarios WHERE correo=?',['admin@example.test'])['id'],'rol'=>'ADMINISTRADOR'];
$employeeId=CatalogService::save('usuarios',['nombre_completo'=>'Empleado de prueba','correo'=>'empleado@example.test','rol_id'=>2,'password'=>$password],$adminUser);$employee=['id'=>$employeeId,'rol'=>'EMPLEADO'];
test('credenciales incorrectas',fn()=>expect(!Auth::login('admin@example.test','incorrecta')));
test('correo inexistente',fn()=>expect(!Auth::login('nadie@example.test','incorrecta')));
test('login administrador y sesión',function()use($password){expect(Auth::login('admin@example.test',$password));expect(Auth::current()['rol']==='ADMINISTRADOR');});
test('logout',function(){Auth::logout();expect(Auth::current()===null);});
test('login empleado',function()use($password){expect(Auth::login('empleado@example.test',$password));});
$program=CatalogService::save('programas_academicos',['codigo'=>'TEST','nombre'=>'Programa sintético'],$adminUser);
$category=CatalogService::save('categorias_recursos',['nombre'=>'Categoría sintética','descripcion'=>'Solo pruebas'],$adminUser);
$student=CatalogService::save('estudiantes',['codigo_estudiante'=>'TEST-1','nombres'=>'Persona','apellidos'=>'Sintética','correo'=>'estudiante@example.test','programa_id'=>$program],$employee);
$resource=CatalogService::save('recursos',['codigo_inventario'=>'TEST-R1','nombre'=>'Recurso sintético','descripcion'=>'Solo pruebas','categoria_id'=>$category,'estado_fisico'=>'BUENO','estado_operativo'=>'DISPONIBLE'],$adminUser);
test('QR opaco único',function()use($student){$r=DB::one('SELECT qr_token FROM estudiantes WHERE id=?',[$student]);expect((bool)preg_match('/^[a-f0-9]{64}$/',$r['qr_token']));});
test('actualizar estudiante conserva QR',function()use($student,$employee){$s=DB::one('SELECT * FROM estudiantes WHERE id=?',[$student]);CatalogService::save('estudiantes',array_replace($s,['nombres'=>'Persona actualizada']),$employee);$updated=DB::one('SELECT * FROM estudiantes WHERE id=?',[$student]);expect($updated['nombres']==='Persona actualizada' && $updated['qr_token']===$s['qr_token']);});
test('desactivación lógica conserva registro',function()use($student,$employee){CatalogService::toggle('estudiantes',$student,$employee);expect((int)DB::one('SELECT activo FROM estudiantes WHERE id=?',[$student])['activo']===0);CatalogService::toggle('estudiantes',$student,$employee);});
test('empleado no crea inventario',fn()=>CatalogService::save('categorias_recursos',['nombre'=>'Prohibida','descripcion'=>''],$employee),true);
test('duplicado código estudiante',function()use($student){$s=DB::one('SELECT * FROM estudiantes WHERE id=?',[$student]);try{CatalogService::save('estudiantes',array_replace($s,['id'=>'']),$GLOBALS['employee']);}catch(PDOException){return;}throw new RuntimeException('No rechazó duplicado.');});
$future=['tipo_prestamo'=>'EXTERNO','estudiante_id'=>$student,'recurso_id'=>$resource,'inicio'=>'2099-01-01T10:00','fin'=>'2099-01-01T11:00'];
test('autorización académica obligatoria',fn()=>Lending::reserve($future,$employeeId),true);
Administration::authorization(['programa_id'=>$program,'categoria_id'=>$category,'permitir'=>'1'],$adminUser);
$reservation=Lending::reserve($future,$employeeId);
test('reserva solapada rechazada',fn()=>Lending::reserve($future,$employeeId),true);
test('reserva contigua aceptada',fn()=>Lending::reserve(array_replace($future,['inicio'=>'2099-01-01T11:00','fin'=>'2099-01-01T12:00']),$employeeId));
test('cancelación de reserva',fn()=>Lending::cancel($reservation));
$loanInput=['tipo_prestamo'=>'EXTERNO','estudiante_id'=>$student,'recurso_id'=>$resource,'fin'=>date('Y-m-d\\TH:i',time()+3600),'estado_fisico'=>'BUENO','observaciones'=>'Inspección de prueba'];$loan=Lending::lend($loanInput,$employeeId);
test('préstamo cambia inventario',fn()=>expect(DB::one('SELECT estado_operativo FROM recursos WHERE id=?',[$resource])['estado_operativo']==='PRESTADO'));
test('préstamo duplicado rechazado',fn()=>Lending::lend($loanInput,$employeeId),true);
test('reserva con préstamo activo rechazada',fn()=>Lending::reserve($future,$employeeId),true);
test('índice único protege escritura directa',function()use($student,$resource,$employeeId){try{DB::run('INSERT INTO prestamos(estudiante_id,recurso_id,entregado_por,fecha_limite) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))',[$student,$resource,$employeeId]);}catch(PDOException $e){expect($e->getCode()==='23000');return;}throw new RuntimeException('No rechazó duplicado');});
test('desactivación bloqueada por préstamo',fn()=>CatalogService::toggle('recursos',$resource,$adminUser),true);
test('inspección periódica no libera un préstamo',function()use($resource,$employeeId){Lending::inspect(['recurso_id'=>$resource,'estado_fisico'=>'BUENO','apto'=>'1'],$employeeId);expect(DB::one('SELECT estado_operativo FROM recursos WHERE id=?',[$resource])['estado_operativo']==='PRESTADO');});
test('devolución consistente',function()use($loan,$employeeId,$resource){Lending::returnLoan(['prestamo_id'=>$loan,'estado_fisico'=>'BUENO','apto'=>'1','observaciones'=>'Sin novedades'],$employeeId);expect(DB::one('SELECT estado_operativo FROM recursos WHERE id=?',[$resource])['estado_operativo']==='DISPONIBLE');});
test('devolución duplicada rechazada',fn()=>Lending::returnLoan(['prestamo_id'=>$loan,'estado_fisico'=>'BUENO','apto'=>'1'],$employeeId),true);
test('inspección no apta bloquea inventario',function()use($resource,$employeeId){Lending::inspect(['recurso_id'=>$resource,'estado_fisico'=>'DANADO','apto'=>'0','observaciones'=>'Daño sintético'],$employeeId);expect(DB::one('SELECT estado_operativo FROM recursos WHERE id=?',[$resource])['estado_operativo']==='MANTENIMIENTO');});
test('mora menor a 24h rechazada',fn()=>Administration::sanction(['estudiante_id'=>$student,'prestamo_id'=>$loan,'tipo'=>'MORA','motivo'=>'Reglamento de prueba'],$adminUser),true);
DB::run('UPDATE prestamos SET fecha_entrega=DATE_SUB(NOW(),INTERVAL 4 DAY),fecha_limite=DATE_SUB(NOW(),INTERVAL 2 DAY) WHERE id=?',[$loan]);
test('mora superior a 24h manual',fn()=>Administration::sanction(['estudiante_id'=>$student,'prestamo_id'=>$loan,'tipo'=>'MORA','motivo'=>'Reglamento sintético solo para prueba'],$adminUser));
$sanction=(int)DB::get()->lastInsertId();$sanction=(int)DB::one('SELECT MAX(id) id FROM sanciones')['id'];
test('resolución de sanción',fn()=>Administration::resolve(['id'=>$sanction,'estado'=>'RESUELTA','observaciones'=>'Resolución sintética'],$adminUser));
test('auditoría presente',fn()=>expect((int)DB::one("SELECT COUNT(*) n FROM auditoria WHERE accion='DEVOLVER'")['n']===1));
test('sanciones sin tarifa inventada',fn()=>expect(DB::one('SELECT monto FROM sanciones WHERE id=?',[$sanction])['monto']===null));
test('cuenta inactiva no ingresa',function()use($employeeId,$password){DB::run('UPDATE usuarios SET activo=0 WHERE id=?',[$employeeId]);Auth::logout();expect(!Auth::login('empleado@example.test',$password));DB::run('UPDATE usuarios SET activo=1 WHERE id=?',[$employeeId]);});
test('límite persistente de intentos',function(){for($i=0;$i<12;$i++){try{Auth::login('nadie@example.test','incorrecta');}catch(DomainException $e){expect($e->getCode()===429);return;}}throw new RuntimeException('Sin límite');});
test('QR SVG genera una matriz',function()use($root,$student){require $root.'/vendor/autoload.php';$r=new BaconQrCode\Renderer\ImageRenderer(new BaconQrCode\Renderer\RendererStyle\RendererStyle(280),new BaconQrCode\Renderer\Image\SvgImageBackEnd());$svg=(new BaconQrCode\Writer($r))->writeString(DB::one('SELECT qr_token FROM estudiantes WHERE id=?',[$student])['qr_token']);expect(str_contains($svg,'<svg'));});
file_put_contents($root.'/var/test-fixture.json',json_encode(['database'=>$name,'correo'=>'admin@example.test','empleado'=>'empleado@example.test','password'=>$password,'student'=>$student,'resource'=>$resource],JSON_PRETTY_PRINT));
echo "$count casos, $fail fallos. Base aislada: $name\n";$out=ob_get_clean();echo $out;file_put_contents($root.'/var/test-results/integration.txt',$out);exit($fail?1:0);
