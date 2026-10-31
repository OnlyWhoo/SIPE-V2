<?php
declare(strict_types=1);
namespace Sipe\Controllers;
use Sipe\Models\Database as DB;
use Sipe\Middleware\Security as S;
use Sipe\Services\{Workflow,LoanPolicy,Lending,Audit};
final class WorkflowController
{
    public function handle(string $route,array $user,bool $post): void {
        $categories=DB::all('SELECT id,nombre FROM categorias_recursos WHERE activo=1 ORDER BY nombre');
        $data=compact('user','route','categories');
        if($route==='requests'){
            if($post){if(($_POST['action']??'')==='approve'){Lending::approve(S::id($_POST['id']??0),S::text($_POST['reason']??'',1000),$user);$_SESSION['flash']='Solicitud aprobada y reserva confirmada.';}elseif(($_POST['action']??'')==='cancel'){Lending::cancel(S::id($_POST['id']??0));$_SESSION['flash']='Solicitud cancelada. El historial se conserva.';}else{throw new \DomainException('Acción inválida.');}\redirect('requests');}
            $data+=Workflow::movements($_GET);$data['title']='Solicitudes y préstamos';
        }elseif($route==='borrower'){
            $identity=S::text($_GET['identity']??'',64,false);$student=$identity===''?null:Workflow::student($identity);
            $history=($_GET['mode']??'')==='history';$groups=LoanPolicy::groups();$type=S::text($_GET['tipo_prestamo']??'',10,false);
            $sanctions=$student?DB::all('SELECT tipo,motivo,estado,fecha_registro FROM sanciones WHERE estudiante_id=? ORDER BY id DESC LIMIT 30',[$student['id']]):[];
            $data+=compact('identity','student','history','groups','type','sanctions');$data['title']=$history?'Hoja de Vida del Estudiante':'Identificación del prestatario';
        }elseif($route==='resources' || $route==='confirm'){
            $in=$post?$_POST:$_GET;[$type,$group]=LoanPolicy::options($in);$student=Workflow::byId(S::id($in['estudiante_id']??0));
            if($group!==$student['tipo_persona']){throw new \DomainException('El grupo debe coincidir con la ficha del prestatario.');}
            if(!$student['activo'] || ($student['tipo_persona']==='ESTUDIANTE' && !$student['programa_activo'])){throw new \DomainException('El estudiante o su programa está inactivo en SIPE.');}
            $day=Workflow::date((string)($in['day']??date('Y-m-d')));$time=S::text($in['time']??date('H:i'),5);
            if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time)){throw new \DomainException('Hora inválida.');}
            $in['inicio']=$day.'T'.$time;$closing=LoanPolicy::closing();
            if($type==='INTERNO' && empty($in['fin'])){$in['fin']=$day.'T'.$closing;}
            $reference=S::text($in['reference']??'',100,false);$category=empty($in['categoria'])?null:S::id($in['categoria']);
            $params=['%'.$reference.'%','%'.$reference.'%'];$where='';if($category){$where=' AND r.categoria_id=?';$params[]=$category;}
            $resources=DB::all("SELECT r.*,c.nombre categoria FROM recursos r JOIN categorias_recursos c ON c.id=r.categoria_id WHERE r.activo=1 AND c.activo=1 AND (r.codigo_inventario LIKE ? OR r.nombre LIKE ?)$where ORDER BY r.codigo_inventario LIMIT 100",$params);
            $resource=empty($in['recurso_id'])?null:DB::one('SELECT r.*,c.nombre categoria FROM recursos r JOIN categorias_recursos c ON c.id=r.categoria_id WHERE r.id=?',[S::id($in['recurso_id'])]);
            $slots=$resource?Workflow::availability((int)$resource['id'],$day):[];
            $interval=null;
            if($route==='confirm'){
                if(!$post || !$resource){throw new \DomainException('Selecciona un recurso y revisa el resumen.');}
                if(in_array($in['action']??'',['reserve','lend'],true)){
                    if($in['action']==='reserve'){Lending::reserve($in,(int)$user['id']);$_SESSION['flash']='Solicitud registrada. Revisa su estado en el panel; egresados y administrativos requieren aprobación manual.';}
                    else{
                        $start=strtotime($in['inicio']);
                        if(abs(time()-$start)>300){throw new \DomainException('La entrega es inmediata. Ajusta el inicio a la hora actual o registra una reserva.');}
                        Lending::lend($in,(int)$user['id']);$_SESSION['flash']='Entrega física registrada con su responsable e inspección.';
                    }
                    \redirect('requests');
                }
                $interval=Lending::preview($in);
            }
            $data+=compact('in','student','type','group','day','time','closing','reference','category','resources','resource','slots','interval');$data['title']=$route==='confirm'?'Confirmar solicitud':'Selección de recursos';
        }elseif($route==='deliver'){
            $id=S::id(($post?$_POST:$_GET)['id']??0);$reservation=DB::one('SELECT * FROM reservas WHERE id=?',[$id]);
            if(!$reservation || $reservation['estado']!=='CONFIRMADA'){throw new \DomainException('Selecciona una reserva confirmada.');}
            if($reservation['inicio']>date('Y-m-d H:i:s') || $reservation['fin']<=date('Y-m-d H:i:s')){throw new \DomainException('La entrega solo puede registrarse durante el horario reservado.');}
            if($post){
                $in=$_POST+['estudiante_id'=>$reservation['estudiante_id'],'recurso_id'=>$reservation['recurso_id'],'reserva_id'=>$id,'fin'=>str_replace(' ','T',substr($reservation['fin'],0,16)),'grupo_trabajo'=>$reservation['grupo_trabajo']??Workflow::byId((int)$reservation['estudiante_id'])['tipo_persona']];
                // Identidades, intervalo y grupo siempre se obtienen del registro persistido.
                foreach(['estudiante_id','recurso_id','reserva_id','fin','grupo_trabajo'] as $key){$in[$key]=match($key){'reserva_id'=>$id,'fin'=>str_replace(' ','T',substr($reservation['fin'],0,16)),'grupo_trabajo'=>$reservation['grupo_trabajo']??Workflow::byId((int)$reservation['estudiante_id'])['tipo_persona'],default=>$reservation[$key]};}
                if($reservation['tipo_prestamo']!==null){$in['tipo_prestamo']=$reservation['tipo_prestamo'];}
                Lending::lend($in,(int)$user['id']);$_SESSION['flash']='Entrega registrada. La reserva quedó atendida.';\redirect('requests');
            }
            $student=Workflow::byId((int)$reservation['estudiante_id']);$resource=DB::one('SELECT nombre,codigo_inventario FROM recursos WHERE id=?',[$reservation['recurso_id']]);
            $data+=compact('reservation','student','resource');$data['title']='Reportar Entrega';
        }elseif($route==='return-loan'){
            $id=S::id(($post?$_POST:$_GET)['id']??0);$loan=DB::one("SELECT p.*,r.nombre recurso,r.codigo_inventario FROM prestamos p JOIN recursos r ON r.id=p.recurso_id WHERE p.id=? AND p.estado='ACTIVO'",[$id]);
            if(!$loan){throw new \DomainException('Selecciona un préstamo activo.');}
            if($post){$in=$_POST;$in['prestamo_id']=$id;Lending::returnLoan($in,(int)$user['id']);$_SESSION['flash']='Devolución registrada. El inventario fue actualizado.';\redirect('requests');}
            $student=Workflow::byId((int)$loan['estudiante_id']);$data+=compact('loan','student');$data['title']='Reportar Devolución';
        }elseif($route==='voucher'){
            $voucher=Workflow::voucher(S::id($_GET['id']??0));Audit::record('GENERAR_VALE','prestamos',(int)$voucher['id']);$data+=compact('voucher');$data['title']='Vale de préstamo externo';
        }elseif($route==='loan-settings'){
            S::admin($user);
            if($post){$closing=S::text($_POST['closing']??'',5);if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$closing)){throw new \DomainException('Hora de cierre inválida.');}
                DB::transaction(function()use($closing,$user): void{DB::run("UPDATE configuraciones SET valor=?,actualizado_por=? WHERE clave='hora_limite_interno'",[$closing,$user['id']]);Audit::record('REGLAS_PRESTAMO','configuraciones',null,'Hora límite interna actualizada');});$_SESSION['flash']='Regla actualizada. Las solicitudes existentes conservan su plazo.';\redirect('loan-settings');
            }
            $data+=['closing'=>LoanPolicy::closing(),'groups'=>LoanPolicy::groups(),'title'=>'Reglas de préstamo'];
        }
        \view('workflow',$data);
    }
}
