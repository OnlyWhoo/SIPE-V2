<?php
declare(strict_types=1);
namespace Sipe\Controllers;
use Sipe\Models\Database as DB;
use Sipe\Models\Catalog;
use Sipe\Middleware\Security as S;
use Sipe\Services\{Auth,Audit,CatalogService,Lending,Administration,Profiles,Sanctions,Workflow,SearchFilters};
final class Application
{
    public function handle(string $route): void {
        $user=Auth::current(); $post=$_SERVER['REQUEST_METHOD']==='POST';
        if($post){S::verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null);}
        if($route==='login'){
            if($user){\redirect('dashboard');}
            $error=null;
            if($post){$email=S::text($_POST['correo']??'',180);$password=$_POST['password']??'';if(!is_string($password)||strlen($password)>1000){throw new \DomainException('Credenciales inválidas.');}if(Auth::login($email,$password)){\redirect('dashboard');}$error='Correo o contraseña incorrectos.';http_response_code(422);}
            \view('login',compact('error','user')+['title'=>'Iniciar sesión']);return;
        }
        if(!$user){\redirect('login');}
        if(in_array($route,['requests','borrower','resources','confirm','deliver','return-loan','voucher','loan-settings'],true)){
            (new WorkflowController())->handle($route,$user,$post);return;
        }
        if(in_array($route,['reservations','loans'],true)){
            if($post){throw new \DomainException('Utiliza el panel Solicitud de Recursos para registrar movimientos.');}
            (new WorkflowController())->handle('requests',$user,false);return;
        }
        if($route==='reports'){
            S::admin($user);
            if($post){SearchFilters::submit('reports',\url('reports'),['report','identity','from','to','categoria']);}
            $data=\Sipe\Services\Reports::query(SearchFilters::apply('reports'));$categories=DB::all('SELECT id,nombre FROM categorias_recursos ORDER BY nombre');
            \view('report-center',$data+compact('user','categories')+['title'=>'Reportes de Recursos']);return;
        }
        if($route==='logout'){if(!$post){throw new \DomainException('Usa el botón de cierre de sesión.',405);}Audit::record('CIERRE_SESION','usuarios',(int)$user['id']);Auth::logout();\redirect('login');}
        if($route==='dashboard'){
            $counts=[];foreach(['Disponibles'=>"SELECT COUNT(*) n FROM recursos r JOIN categorias_recursos c ON c.id=r.categoria_id WHERE r.activo=1 AND c.activo=1 AND r.estado_operativo='DISPONIBLE' AND r.estado_fisico<>'DANADO'",'Préstamos activos'=>"SELECT COUNT(*) n FROM prestamos WHERE estado='ACTIVO'",'Reservas'=>"SELECT COUNT(*) n FROM reservas WHERE estado='CONFIRMADA' AND fin>NOW()",'Vencidos'=>"SELECT COUNT(*) n FROM prestamos WHERE estado='ACTIVO' AND fecha_limite<NOW()"] as $label=>$sql){$counts[$label]=(int)DB::one($sql)['n'];}
            if($user['rol']==='ADMINISTRADOR'){$counts['Sanciones abiertas']=(int)DB::one("SELECT COUNT(*) n FROM sanciones WHERE estado='ABIERTA'")['n'];}
            $rows=DB::all('SELECT * FROM vw_prestamos_resumen ORDER BY prestamo_id DESC LIMIT 8');\view('dashboard',compact('user','counts','rows')+['title'=>'Panel principal']);return;
        }
        if($route==='catalog'){
            $table=S::text($_GET['table']??'estudiantes',40);$def=Catalog::definition($table);
            if($table==='usuarios'){S::admin($user);}
            if($post){
                if(($_POST['action']??'')==='search'){
                    SearchFilters::submit('catalog:'.$table,\url('catalog').'&table='.rawurlencode($table),['q']);
                }
                if(($_POST['action']??'')==='toggle'){
                    CatalogService::toggle($table,S::id($_POST['id']??0),$user);
                }else{
                    $profile=array_intersect_key($_POST,['cedula'=>true,'carnet_vigencia'=>true]);
                    if(isset($_FILES['fotografia'])){$profile['fotografia']=$_FILES['fotografia'];}
                    CatalogService::save($table,$_POST,$user,$profile);
                }
                $_SESSION['flash']='Registro actualizado correctamente.';header('Location: '.\url('catalog').'&table='.rawurlencode($table),true,303);exit;
            }
            $filters=SearchFilters::apply('catalog:'.$table);
            $query=S::text($filters['q']??'',150,false);$search=array_key_first($def['fields']);$params=[];$where='';
            $pagination=isset($filters['filter'])?'&filter='.rawurlencode(S::text($filters['filter'],32,false)):
                ($query!==''?'&q='.rawurlencode($query):'');
            if($query!==''){$where=" WHERE $search LIKE ?";$params[]='%'.$query.'%';}
            $page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*50;
            $cols=implode(',',array_filter(array_keys($def['fields']),fn($f)=>$f!=='password'));
            $rows=DB::all("SELECT id,activo,$cols FROM $table$where ORDER BY id DESC LIMIT 50 OFFSET $offset",$params);
            $profileEditor=$table==='estudiantes' && $user['rol']==='ADMINISTRADOR' && Profiles::enabled();
            $extra=$profileEditor?',cedula,carnet_vigencia':'';
            $edit=null;if(isset($_GET['edit'])){if($table!=='estudiantes'){S::admin($user);}$edit=DB::one("SELECT id,activo,$cols$extra FROM $table WHERE id=?",[S::id($_GET['edit'])]);}
            \view('catalog',compact('user','table','def','rows','edit','query','page','profileEditor','pagination')+['title'=>$def['title']]);return;
        }
        if($route==='photo' && Profiles::enabled()){Profiles::servePhoto(S::id($_GET['id']??0));return;}
        if($route==='student' || $route==='qr'){
            $student=isset($_GET['token'])?Workflow::byToken(S::text($_GET['token'],64)):Workflow::byId(S::id($_GET['id']??0));
            if($route==='qr'){
                require ROOT.'/vendor/autoload.php';$renderer=new \BaconQrCode\Renderer\ImageRenderer(new \BaconQrCode\Renderer\RendererStyle\RendererStyle(280),new \BaconQrCode\Renderer\Image\SvgImageBackEnd());
                header('Content-Type: image/svg+xml');echo (new \BaconQrCode\Writer($renderer))->writeString($student['qr_token']);return;
            }
            Audit::record('CONSULTAR_HOJA','estudiantes',(int)$student['id']);
            $id=$student['id'];
            $rows=DB::all("SELECT CONCAT('P',p.id) AS id,r.nombre AS recurso,p.fecha_entrega AS inicio,p.fecha_limite AS limite,
                CASE WHEN d.fecha_devolucion IS NOT NULL THEN CONCAT(p.estado,' · ',DATE_FORMAT(d.fecha_devolucion,'%d/%m/%Y %H:%i')) ELSE p.estado END AS estado
                FROM prestamos p JOIN recursos r ON r.id=p.recurso_id LEFT JOIN devoluciones d ON d.prestamo_id=p.id
                WHERE p.estudiante_id=?
                UNION ALL
                SELECT CONCAT('R',q.id),r.nombre,q.inicio,q.fin,q.estado FROM reservas q JOIN recursos r ON r.id=q.recurso_id
                WHERE q.estudiante_id=? ORDER BY inicio DESC LIMIT 100",[$id,$id]);
            $totals=DB::one("SELECT
                (SELECT COUNT(*) FROM prestamos WHERE estudiante_id=? AND estado='ACTIVO') AS activos,
                (SELECT COUNT(*) FROM reservas WHERE estudiante_id=? AND estado='CONFIRMADA' AND fin>NOW()) AS reservas,
                (SELECT COUNT(*) FROM devoluciones d JOIN prestamos p ON p.id=d.prestamo_id WHERE p.estudiante_id=?) AS devoluciones",[$id,$id,$id]);
            $blocked=Sanctions::hasOpen((int)$id);
            $sanctions=DB::all('SELECT tipo,motivo,estado,fecha_registro FROM sanciones WHERE estudiante_id=? ORDER BY id DESC LIMIT 100',[$id]);$allowed=DB::all('SELECT c.nombre FROM autorizaciones_programa_categoria a JOIN categorias_recursos c ON c.id=a.categoria_id WHERE a.programa_id=? AND c.activo=1',[$student['programa_id']]);\view('student',compact('user','student','rows','allowed','sanctions','totals','blocked')+['title'=>'Hoja de Vida del Usuario']);return;
        }
        if($route==='scan'){
            if($post){$student=\Sipe\Services\Workflow::student(S::text($_POST['token']??'',64));header('Location: '.\url('student').'&id='.$student['id'],true,303);exit;}
            \view('scan',compact('user')+['title'=>'Identificar usuario']);return;
        }
        if(in_array($route,['reservations','loans','inspections'],true)){
            if($post){match($_POST['action']??''){'reserve'=>Lending::reserve($_POST,(int)$user['id']),'cancel'=>Lending::cancel(S::id($_POST['id']??0)),'lend'=>Lending::lend($_POST,(int)$user['id']),'return'=>Lending::returnLoan($_POST,(int)$user['id']),'inspect'=>Lending::inspect($_POST,(int)$user['id']),default=>throw new \DomainException('Acción inválida.')};$_SESSION['flash']='Operación registrada correctamente.';\redirect($route);}
            $students=DB::all('SELECT id,CONCAT(codigo_estudiante,\' · \',nombres,\' \',apellidos) AS nombre FROM estudiantes WHERE activo=1 ORDER BY codigo_estudiante');$resources=DB::all('SELECT id,CONCAT(codigo_inventario,\' · \',nombre,\' / \',estado_operativo) AS nombre FROM recursos WHERE activo=1 ORDER BY nombre');
            $rows=match($route){'reservations'=>DB::all('SELECT q.*,CONCAT(e.nombres,\' \',e.apellidos) AS estudiante,r.nombre AS recurso FROM reservas q JOIN estudiantes e ON e.id=q.estudiante_id JOIN recursos r ON r.id=q.recurso_id ORDER BY q.id DESC LIMIT 100'),'loans'=>DB::all('SELECT p.*,CONCAT(e.nombres,\' \',e.apellidos) AS estudiante,r.nombre AS recurso FROM prestamos p JOIN estudiantes e ON e.id=p.estudiante_id JOIN recursos r ON r.id=p.recurso_id ORDER BY p.id DESC LIMIT 100'),default=>DB::all('SELECT i.*,r.nombre AS recurso FROM inspecciones i JOIN recursos r ON r.id=i.recurso_id ORDER BY i.id DESC LIMIT 100')};
            $title=match($route){'reservations'=>'Solicitar Recursos','loans'=>'Entrega / Devolución',default=>'Inspección de Ítems'};\view('operations',compact('user','route','students','resources','rows','title'));return;
        }
        if($route==='authorizations'){
            S::admin($user);if($post){Administration::authorization($_POST,$user);$_SESSION['flash']='Regla académica actualizada.';\redirect($route);}
            $programs=Catalog::options('programa_id');$categories=Catalog::options('categoria_id');$rows=DB::all('SELECT p.nombre AS programa,c.nombre AS categoria,a.creado_en FROM autorizaciones_programa_categoria a JOIN programas_academicos p ON p.id=a.programa_id JOIN categorias_recursos c ON c.id=a.categoria_id');\view('authorizations',compact('user','programs','categories','rows')+['title'=>'Autorización académica']);return;
        }
        if($route==='sanctions'){
            (new SanctionsController())->handle($user,$post);return;
        }
        if($route==='audit' || $route==='reports'){
            S::admin($user);$from=S::text($_GET['from']??date('Y-m-01'),10);$to=S::text($_GET['to']??date('Y-m-d'),10);foreach([$from,$to] as $date){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d || $d->format('Y-m-d')!==$date){throw new \DomainException('Fecha de filtro inválida.');}}
            if($from>$to){throw new \DomainException('El inicio del filtro supera el final.');}$filter=S::text($_GET['q']??'',150,false);$report=S::text($_GET['report']??'prestamos',30);
            $defs=['prestamos'=>['vw_prestamos_resumen','fecha_entrega','recurso'],'reservas'=>['reservas','creada_en','estado'],'inventario'=>['recursos','creado_en','nombre'],'sanciones'=>['sanciones','fecha_registro','tipo'],'inspecciones'=>['inspecciones','fecha_inspeccion','estado_fisico'],'devoluciones'=>['devoluciones','fecha_devolucion','estado_retorno']];
            if($route==='audit'){$rows=DB::all('SELECT a.id,u.nombre_completo AS responsable,a.accion,a.entidad,a.entidad_id,a.detalle,a.fecha_evento FROM auditoria a LEFT JOIN usuarios u ON u.id=a.usuario_id WHERE a.fecha_evento>=? AND a.fecha_evento<DATE_ADD(?,INTERVAL 1 DAY) AND (a.entidad LIKE ? OR u.nombre_completo LIKE ?) ORDER BY a.id DESC LIMIT 200',[$from,$to,'%'.$filter.'%','%'.$filter.'%']);}else{[$t,$date,$col]=$defs[$report]??throw new \DomainException('Reporte inválido.');$rows=DB::all("SELECT * FROM $t WHERE $date>=? AND $date<DATE_ADD(?,INTERVAL 1 DAY) AND $col LIKE ? ORDER BY $date DESC LIMIT 200",[$from,$to,'%'.$filter.'%']);foreach($rows as &$row){unset($row['qr_token']);}unset($row);}
            \view('reports',compact('user','route','rows','from','to','filter','report','defs')+['title'=>$route==='audit'?'Auditoría':'Reportes']);return;
        }
        throw new \DomainException('Página no encontrada.',404);
    }
}
