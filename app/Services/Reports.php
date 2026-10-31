<?php
declare(strict_types=1);
namespace Sipe\Services;
use Sipe\Models\Database as DB;
use Sipe\Middleware\Security as S;
final class Reports
{
    public const LABELS=['activos'=>'Préstamos activos','vencidos'=>'Préstamos vencidos','pendientes'=>'Pendientes de devolución','canceladas'=>'Solicitudes canceladas','historial'=>'Historial por prestatario','fechas'=>'Préstamos por fechas','utilizados'=>'Recursos más utilizados','sanciones'=>'Sanciones','mantenimiento'=>'Recursos en mantenimiento','reservas'=>'Reservas','inventario'=>'Inventario general','inspecciones'=>'Inspecciones','devoluciones'=>'Devoluciones registradas'];
    public static function query(array $in): array {
        $report=S::text($in['report']??'activos',30);if($report==='prestamos'){$report='fechas';}if(!isset(self::LABELS[$report])){throw new \DomainException('Reporte inválido.');}
        $from=empty($in['from'])?null:Workflow::date((string)$in['from']);$to=empty($in['to'])?null:Workflow::date((string)$in['to']);if($from && $to && $from>$to){throw new \DomainException('El inicio supera el final.');}
        $identity=S::text($in['identity']??'',64,false);$category=empty($in['categoria'])?null:S::id($in['categoria']);$params=[];$where=' WHERE 1=1';
        if(in_array($report,['inventario','inspecciones','devoluciones'],true)){
            $date=match($report){'inventario'=>'r.creado_en','inspecciones'=>'m.fecha_inspeccion',default=>'m.fecha_devolucion'};
            $sql=match($report){
                'inventario'=>"SELECT r.codigo_inventario Referencia,r.nombre Recurso,c.nombre Categoría,r.estado_fisico 'Estado físico',r.estado_operativo Estado,r.activo Activo FROM recursos r JOIN categorias_recursos c ON c.id=r.categoria_id",
                'inspecciones'=>"SELECT m.id Inspección,r.codigo_inventario Referencia,r.nombre Recurso,m.momento Momento,m.estado_fisico 'Estado físico',m.apto_para_prestamo Apto,m.observaciones Observaciones,m.fecha_inspeccion Fecha,u.nombre_completo Responsable FROM inspecciones m JOIN recursos r ON r.id=m.recurso_id JOIN usuarios u ON u.id=m.inspeccionado_por LEFT JOIN prestamos p ON p.id=m.prestamo_id LEFT JOIN estudiantes e ON e.id=p.estudiante_id",
                default=>"SELECT m.id Devolución,p.id Préstamo,e.codigo_estudiante Código,CONCAT(e.nombres,' ',e.apellidos) Prestatario,r.codigo_inventario Referencia,r.nombre Recurso,m.estado_retorno 'Estado físico',m.fecha_devolucion Fecha,u.nombre_completo Responsable FROM devoluciones m JOIN prestamos p ON p.id=m.prestamo_id JOIN estudiantes e ON e.id=p.estudiante_id JOIN recursos r ON r.id=p.recurso_id JOIN usuarios u ON u.id=m.recibido_por"
            };
            if($from){$where.=" AND $date>=?";$params[]=$from;}if($to){$where.=" AND $date<DATE_ADD(?,INTERVAL 1 DAY)";$params[]=$to;}
            if($category){$where.=' AND r.categoria_id=?';$params[]=$category;}
            if($identity!=='' && $report!=='inventario'){$where.=' AND e.codigo_estudiante=?';$params[]=$identity;}
            $rows=DB::all($sql.$where." ORDER BY $date DESC LIMIT 200",$params);return compact('rows','report','from','to','identity','category');
        }
        if($report==='mantenimiento'){
            $rows=DB::all("SELECT r.codigo_inventario Referencia,r.nombre Recurso,c.nombre Categoría,r.estado_fisico 'Estado físico',r.actualizado_en 'Actualizado' FROM recursos r JOIN categorias_recursos c ON c.id=r.categoria_id WHERE r.estado_operativo='MANTENIMIENTO'".($category?' AND r.categoria_id=?':'').' ORDER BY r.codigo_inventario LIMIT 200',$category?[$category]:[]);
            return compact('rows','report','from','to','identity','category');
        }
        $source=in_array($report,['canceladas','reservas'],true)?'reservas':($report==='sanciones'?'sanciones':'prestamos');$date=match($source){'reservas'=>'m.inicio','sanciones'=>'m.fecha_registro',default=>'m.fecha_entrega'};
        if($from){$where.=" AND $date>=?";$params[]=$from;}if($to){$where.=" AND $date<DATE_ADD(?,INTERVAL 1 DAY)";$params[]=$to;}
        if($identity!==''){$where.=' AND e.codigo_estudiante=?';$params[]=$identity;}
        if($category){$where.=' AND r.categoria_id=?';$params[]=$category;}
        if(in_array($report,['activos','pendientes','vencidos'],true)){$where.=" AND m.estado='ACTIVO'";}if($report==='vencidos'){$where.=' AND m.fecha_limite<NOW()';}if($report==='canceladas'){$where.=" AND m.estado='CANCELADA'";}
        $join=" FROM $source m JOIN estudiantes e ON e.id=m.estudiante_id ";
        if($source==='sanciones'){
            $join.='LEFT JOIN prestamos p ON p.id=m.prestamo_id LEFT JOIN recursos r ON r.id=p.recurso_id ';
            $select="SELECT m.id Sanción,e.codigo_estudiante Código,CONCAT(e.nombres,' ',e.apellidos) Prestatario,m.tipo Tipo,m.motivo Motivo,m.estado Estado,m.fecha_registro Fecha,m.monto Monto";
        }else{
            $join.='JOIN recursos r ON r.id=m.recurso_id JOIN categorias_recursos c ON c.id=r.categoria_id ';
            if($report==='utilizados'){$select='SELECT r.codigo_inventario Referencia,r.nombre Recurso,c.nombre Categoría,COUNT(*) Entregas';}
            elseif($source==='reservas'){$select="SELECT m.id Solicitud,e.codigo_estudiante Código,CONCAT(e.nombres,' ',e.apellidos) Prestatario,r.nombre Recurso,r.codigo_inventario Referencia,m.inicio Inicio,m.fin Fin,m.tipo_prestamo Tipo,m.estado Estado";}
            else{$join.='JOIN usuarios u ON u.id=m.entregado_por LEFT JOIN devoluciones d ON d.prestamo_id=m.id ';$select="SELECT m.id Préstamo,e.codigo_estudiante Código,CONCAT(e.nombres,' ',e.apellidos) Prestatario,r.nombre Recurso,r.codigo_inventario Referencia,c.nombre Categoría,m.tipo_prestamo Tipo,m.fecha_entrega Entrega,m.fecha_limite 'Devolución prevista',d.fecha_devolucion 'Devolución real',m.estado Estado,u.nombre_completo Responsable";}
        }
        $order=$report==='utilizados'?' GROUP BY r.id,r.codigo_inventario,r.nombre,c.nombre ORDER BY Entregas DESC,r.id':" ORDER BY $date DESC,m.id DESC";
        $rows=DB::all($select.$join.$where.$order.' LIMIT 200',$params);return compact('rows','report','from','to','identity','category');
    }
}
