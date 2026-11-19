<?php
declare(strict_types=1);
namespace Sipe\Controllers;
use Sipe\Models\Database as DB;
use Sipe\Middleware\Security as S;
use Sipe\Services\{Workflow,LoanPolicy,Lending,Audit,Sanctions,SearchFilters};
final class WorkflowController
{
    public function handle(string $route,array $user,bool $post): void {
        $data=compact('user','route')+['formError'=>null];
        if($route==='requests'){
            if($post && ($_POST['action']??'')==='search'){
                SearchFilters::submit('requests',\url('requests'),['q','from','to','categoria','estado']);
            }
            if($post){
                try {
                    if(($_POST['action']??'')!=='cancel'){throw new \DomainException('Acción inválida.');}
                    Lending::cancel(S::id($_POST['id']??0));
                    $_SESSION['flash']='Solicitud cancelada. El historial se conserva.';
                    \redirect('requests');
                } catch(\DomainException $error) {
                    $data['formError']=self::validationError($error);
                }
            }
            $data['categories']=DB::all('SELECT id,nombre FROM categorias_recursos WHERE activo=1 ORDER BY nombre');
            $data+=Workflow::movements(SearchFilters::apply('requests'));$data['title']='Solicitudes y préstamos';
        }elseif($route==='borrower'){
            $history=($_GET['mode']??'')==='history';$groups=LoanPolicy::groups();$type=S::text($_GET['tipo_prestamo']??'',10,false);
            if($post){
                $matched=Workflow::student(S::text($_POST['identity']??'',64));
                header('Location: '.\url('borrower').'&person='.$matched['id'].($history?'&mode=history':''),true,303);return;
            }
            $identity=S::text($_GET['identity']??'',64,false);
            $student=isset($_GET['person'])?Workflow::byId(S::id($_GET['person'])):($identity===''?null:Workflow::student($identity));
            $identity=$student['codigo_estudiante']??$identity;
            $sanctions=$student?DB::all('SELECT tipo,motivo,estado,fecha_registro FROM sanciones WHERE estudiante_id=? ORDER BY id DESC LIMIT 30',[$student['id']]):[];
            $blocked=$student && Sanctions::hasOpen((int)$student['id']);
            $data+=compact('identity','student','history','groups','type','sanctions','blocked');$data['title']=$history?'Hoja de Vida del Usuario':'Identificación del prestatario';
        }elseif($route==='resources' || $route==='confirm'){
            $in=$post?$_POST:$_GET;
            $student=Workflow::byId(S::id($in['estudiante_id']??0));
            if($route==='resources' && Sanctions::hasOpen((int)$student['id'])){
                $this->showBlocked($data,$student,'Selección de recursos');
                return;
            }
            [$type,$group]=LoanPolicy::options($in);
            if($group!==$student['tipo_persona']){throw new \DomainException('El grupo debe coincidir con la ficha del prestatario.');}
            if(!$student['activo'] || ($student['tipo_persona']==='ESTUDIANTE' && !$student['programa_activo'])){throw new \DomainException('El estudiante o su programa está inactivo en SIPE.');}
            $day=Workflow::date((string)($in['day']??date('Y-m-d')));$time=S::text($in['time']??date('H:i'),5);
            if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time)){throw new \DomainException('Hora inválida.');}
            $in['inicio']=$day.'T'.$time;$closing=LoanPolicy::closing();
            if($type==='INTERNO' && empty($in['fin'])){$in['fin']=$day.'T'.$closing;}
            $reference=S::text($in['reference']??'',100,false);$category=empty($in['categoria'])?null:S::id($in['categoria']);
            $resource=empty($in['recurso_id'])?null:DB::one(
                'SELECT nombre,codigo_inventario,id FROM recursos WHERE id=?',[S::id($in['recurso_id'])]
            );
            $data+=compact('in','student','type','group','day','time','closing','reference','category','resource');
            if($route==='confirm'){
                if(!$post || !$resource){throw new \DomainException('Selecciona un recurso y revisa el resumen.');}
                $data+=['title'=>'Confirmar solicitud','interval'=>null];
                try {
                    $action=$in['action']??'';
                    if($action==='reserve'){
                        Lending::reserve($in,(int)$user['id']);
                        $_SESSION['flash']='Reserva confirmada.';
                        \redirect('requests');
                    }
                    if($action==='lend'){
                        $start=strtotime($in['inicio']);
                        if(abs(time()-$start)>300){throw new \DomainException('La entrega es inmediata. Ajusta el inicio a la hora actual o registra una reserva.');}
                        Lending::lend($in,(int)$user['id']);
                        $_SESSION['flash']='Entrega física registrada con su responsable e inspección.';
                        \redirect('requests');
                    }
                    if($action!==''){throw new \DomainException('Acción inválida.');}
                    $data['interval']=Lending::preview($in);
                } catch(\DomainException $error) {
                    $data['formError']=self::validationError($error);
                }
            }else{
                $params=['%'.$reference.'%','%'.$reference.'%'];
                $where='';
                if($category){$where=' AND r.categoria_id=?';$params[]=$category;}
                $data['resources']=DB::all("SELECT r.id,r.nombre,r.codigo_inventario,r.estado_operativo,c.nombre categoria
                    FROM recursos r JOIN categorias_recursos c ON c.id=r.categoria_id
                    WHERE r.activo=1 AND c.activo=1 AND (r.codigo_inventario LIKE ? OR r.nombre LIKE ?)$where
                    ORDER BY r.codigo_inventario LIMIT 100",$params);
                $data['categories']=DB::all('SELECT id,nombre FROM categorias_recursos WHERE activo=1 ORDER BY nombre');
                $data['slots']=$resource?Workflow::availability((int)$resource['id'],$day):[];
                $data['title']='Selección de recursos';
            }
        }elseif($route==='deliver'){
            $id=S::id(($post?$_POST:$_GET)['id']??0);$reservation=DB::one('SELECT * FROM reservas WHERE id=?',[$id]);
            if(!$reservation || $reservation['estado']!=='CONFIRMADA'){throw new \DomainException('Selecciona una reserva confirmada.');}
            $student=Workflow::byId((int)$reservation['estudiante_id']);
            if(!$post && Sanctions::hasOpen((int)$student['id'])){
                $this->showBlocked($data,$student,'Reportar Entrega');
                return;
            }
            if($reservation['inicio']>date('Y-m-d H:i:s') || $reservation['fin']<=date('Y-m-d H:i:s')){throw new \DomainException('La entrega solo puede registrarse durante el horario reservado.');}
            if($post){
                // El formulario nunca sustituye la identidad, el recurso ni el plazo de la reserva.
                $in=[
                    'estudiante_id'=>$reservation['estudiante_id'],
                    'recurso_id'=>$reservation['recurso_id'],
                    'reserva_id'=>$id,
                    'fin'=>str_replace(' ','T',substr($reservation['fin'],0,16)),
                    'grupo_trabajo'=>$reservation['grupo_trabajo']??$student['tipo_persona'],
                    'tipo_prestamo'=>$reservation['tipo_prestamo']??($_POST['tipo_prestamo']??''),
                ]+$_POST;
                try {
                    Lending::lend($in,(int)$user['id']);
                    $_SESSION['flash']='Entrega registrada. La reserva quedó atendida.';
                    \redirect('requests');
                } catch(\DomainException $error) {
                    if($error->getMessage()===Sanctions::BLOCK_MESSAGE){
                        $this->showBlocked($data,$student,'Reportar Entrega');
                        return;
                    }
                    $data['formError']=self::validationError($error);
                }
            }
            $resource=DB::one('SELECT nombre,codigo_inventario FROM recursos WHERE id=?',[$reservation['recurso_id']]);
            $data+=compact('reservation','student','resource');$data['title']='Reportar Entrega';
        }elseif($route==='return-loan'){
            $id=S::id(($post?$_POST:$_GET)['id']??0);$loan=DB::one("SELECT p.*,r.nombre recurso,r.codigo_inventario FROM prestamos p JOIN recursos r ON r.id=p.recurso_id WHERE p.id=? AND p.estado='ACTIVO'",[$id]);
            if(!$loan){throw new \DomainException('Selecciona un préstamo activo.');}
            if($post){
                try {
                    Lending::returnLoan(['prestamo_id'=>$id]+$_POST,(int)$user['id']);
                    $_SESSION['flash']='Devolución registrada. El inventario fue actualizado.';
                    \redirect('requests');
                } catch(\DomainException $error) {
                    $data['formError']=self::validationError($error);
                }
            }
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

    private function showBlocked(array $data,array $student,string $title): void {
        http_response_code(422);
        $data['formError']=Sanctions::BLOCK_MESSAGE;
        $data['hideOperation']=true;
        \view('workflow',$data+compact('student','title'));
    }

    private static function validationError(\DomainException $error): string {
        if($error->getCode()!==0){throw $error;}
        http_response_code(422);
        return $error->getMessage();
    }
}
