<?php
declare(strict_types=1);
namespace Sipe\Services;
use Sipe\Models\Database as DB;
use Sipe\Middleware\Security as S;
final class Workflow
{
    public static function student(string $identity): array {
        $identity=S::text($identity,64);
        $s=DB::one('SELECT e.*,p.nombre AS programa,p.activo AS programa_activo FROM estudiantes e LEFT JOIN programas_academicos p ON p.id=e.programa_id WHERE e.codigo_estudiante=? OR e.qr_token=?',[$identity,$identity]);
        if(!$s){throw new \DomainException('No se encontró un estudiante con ese código o QR.');}
        return $s;
    }
    public static function byId(int $id): array {
        $s=DB::one('SELECT e.*,p.nombre AS programa,p.activo AS programa_activo FROM estudiantes e LEFT JOIN programas_academicos p ON p.id=e.programa_id WHERE e.id=?',[$id]);
        if(!$s){throw new \DomainException('Estudiante no encontrado.',404);}return $s;
    }
    public static function date(string $day): string {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$day);
        if(!$d || $d->format('Y-m-d')!==$day){throw new \DomainException('Selecciona una fecha válida.');}return $day;
    }
    public static function availability(int $resource,string $day): array {
        self::date($day);
        $r=DB::one('SELECT r.*,c.activo AS categoria_activa FROM recursos r JOIN categorias_recursos c ON c.id=r.categoria_id WHERE r.id=?',[$resource]);
        if(!$r){throw new \DomainException('Recurso no encontrado.',404);}
        $begin=$day.' 00:00:00';$end=(new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d H:i:s');
        if(!$r['activo'] || !$r['categoria_activa'] || in_array($r['estado_operativo'],['MANTENIMIENTO','BAJA'],true) || $r['estado_fisico']==='DANADO'){
            return [['inicio'=>$begin,'fin'=>$end,'estado'=>'No disponible']];
        }
        // El préstamo activo ocupa el recurso hasta su devolución efectiva.
        $busy=DB::all("SELECT GREATEST(fecha_entrega,?) inicio,? fin,'Prestado (pendiente de devolución)' estado FROM prestamos WHERE recurso_id=? AND estado='ACTIVO' AND fecha_entrega<? UNION ALL SELECT GREATEST(inicio,?),LEAST(fin,?),'Reservado' FROM reservas WHERE recurso_id=? AND estado='CONFIRMADA' AND inicio<? AND fin>? ORDER BY inicio",[$begin,$end,$resource,$end,$begin,$end,$resource,$end,$begin]);
        $slots=[];$cursor=$begin;
        foreach($busy as $slot){$slot['inicio']=substr($slot['inicio'],0,19);$slot['fin']=substr($slot['fin'],0,19);if($slot['inicio']>$cursor){$slots[]=['inicio'=>$cursor,'fin'=>$slot['inicio'],'estado'=>'Disponible'];}if($slot['fin']>$cursor){$slots[]=['inicio'=>max($cursor,$slot['inicio']),'fin'=>$slot['fin'],'estado'=>$slot['estado']];$cursor=$slot['fin'];}}
        if($cursor<$end){$slots[]=['inicio'=>$cursor,'fin'=>$end,'estado'=>'Disponible'];}return $slots;
    }
    public static function movements(array $in): array {
        $q=S::text($in['q']??'',150,false);$state=S::text($in['estado']??'ACTIVAS',20);$category=empty($in['categoria'])?null:S::id($in['categoria']);
        $from=empty($in['from'])?null:self::date((string)$in['from']);$to=empty($in['to'])?null:self::date((string)$in['to']);
        if($from && $to && $from>$to){throw new \DomainException('El inicio del filtro supera el final.');}
        $sql="SELECT 'R' origen,q.id,q.estudiante_id,q.recurso_id,q.inicio,NULL fecha_entrega,q.fin,NULL fecha_devolucion,q.tipo_prestamo,q.estado FROM reservas q WHERE q.estado<>'ATENDIDA' UNION ALL SELECT 'P',p.id,p.estudiante_id,p.recurso_id,COALESCE(q.inicio,p.fecha_entrega),p.fecha_entrega,p.fecha_limite,d.fecha_devolucion,p.tipo_prestamo,CASE WHEN p.estado='ACTIVO' AND p.fecha_limite<NOW() THEN 'ATRASADO' ELSE p.estado END FROM prestamos p LEFT JOIN reservas q ON q.id=p.reserva_id LEFT JOIN devoluciones d ON d.prestamo_id=p.id";
        $join=" FROM ($sql) m JOIN estudiantes e ON e.id=m.estudiante_id JOIN recursos r ON r.id=m.recurso_id JOIN categorias_recursos c ON c.id=r.categoria_id WHERE (e.codigo_estudiante LIKE ? OR CONCAT(e.nombres,' ',e.apellidos) LIKE ? OR r.codigo_inventario LIKE ? OR r.nombre LIKE ?)";
        $params=array_fill(0,4,'%'.$q.'%');
        if($state==='ACTIVAS'){$join.=" AND m.estado IN ('PENDIENTE_APROBACION','CONFIRMADA','ACTIVO','ATRASADO')";}
        elseif($state!=='TODAS'){if(!in_array($state,['PENDIENTE_APROBACION','CONFIRMADA','ACTIVO','ATRASADO','DEVUELTO','CANCELADA','VENCIDA','ANULADO'],true)){throw new \DomainException('Estado inválido.');}$join.=' AND m.estado=?';$params[]=$state;}
        if($category){$join.=' AND r.categoria_id=?';$params[]=$category;}
        if($from){$join.=' AND m.inicio>=?';$params[]=$from;}
        if($to){$join.=' AND m.inicio<DATE_ADD(?,INTERVAL 1 DAY)';$params[]=$to;}
        $total=(int)DB::one('SELECT COUNT(*) n'.$join,$params)['n'];$page=max(1,min(max(1,(int)ceil($total/25)),(int)($in['page']??1)));$offset=($page-1)*25;
        $rows=DB::all("SELECT m.*,e.codigo_estudiante,CONCAT(e.nombres,' ',e.apellidos) estudiante,r.nombre recurso,r.codigo_inventario,c.nombre categoria".$join." ORDER BY m.inicio DESC,m.id DESC LIMIT 25 OFFSET $offset",$params);
        return compact('rows','total','page','q','state','category','from','to');
    }
    public static function voucher(int $id): array {
        $p=DB::one("SELECT p.*,e.codigo_estudiante,CONCAT(e.nombres,' ',e.apellidos) estudiante,a.nombre programa,r.nombre recurso,r.codigo_inventario,u.nombre_completo responsable,d.fecha_devolucion FROM prestamos p JOIN estudiantes e ON e.id=p.estudiante_id LEFT JOIN programas_academicos a ON a.id=e.programa_id JOIN recursos r ON r.id=p.recurso_id JOIN usuarios u ON u.id=p.entregado_por LEFT JOIN devoluciones d ON d.prestamo_id=p.id WHERE p.id=?",[$id]);
        if(!$p || $p['tipo_prestamo']!=='EXTERNO'){throw new \DomainException('El vale requiere un préstamo externo registrado.');}return $p;
    }
}
