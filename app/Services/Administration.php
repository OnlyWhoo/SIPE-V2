<?php
declare(strict_types=1);
namespace Sipe\Services;
use Sipe\Models\Database as DB;
use Sipe\Middleware\Security as S;
final class Administration
{
    public static function authorization(array $in,array $user): void {
        S::admin($user);$p=S::id($in['programa_id']??0);$c=S::id($in['categoria_id']??0);
        DB::transaction(function() use($p,$c,$in,$user): void {
            if(!DB::one('SELECT id FROM programas_academicos WHERE id=? AND activo=1 FOR UPDATE',[$p]) || !DB::one('SELECT id FROM categorias_recursos WHERE id=? AND activo=1 FOR UPDATE',[$c])){throw new \DomainException('Programa o categoría inactivo.');}
            if(($in['permitir']??'')==='1'){DB::run('INSERT INTO autorizaciones_programa_categoria(programa_id,categoria_id,autorizado_por) VALUES(?,?,?) ON DUPLICATE KEY UPDATE autorizado_por=VALUES(autorizado_por)',[$p,$c,$user['id']]);}
            else {DB::run('DELETE FROM autorizaciones_programa_categoria WHERE programa_id=? AND categoria_id=?',[$p,$c]);}
            Audit::record('AUTORIZACION_ACADEMICA','autorizaciones_programa_categoria',$p,"Categoría $c; permitir=".(($in['permitir']??'')==='1'?'sí':'no'));
        });
    }
    public static function sanction(array $in,array $user): void {
        S::admin($user);$student=S::id($in['estudiante_id']??0);$loan=empty($in['prestamo_id'])?null:S::id($in['prestamo_id']);$type=S::text($in['tipo']??'',10);$reason=S::text($in['motivo']??'',5000);
        if(!in_array($type,['MORA','DANIO','OTRA'],true)){throw new \DomainException('Tipo de sanción inválido.');}
        DB::transaction(function() use($student,$loan,$type,$reason,$user): void {
            if(!DB::one('SELECT id FROM estudiantes WHERE id=? FOR UPDATE',[$student])){throw new \DomainException('Estudiante inexistente.');}
            $p=$loan?DB::one('SELECT p.*,d.fecha_devolucion FROM prestamos p LEFT JOIN devoluciones d ON d.prestamo_id=p.id WHERE p.id=?',[$loan]):null;
            if($loan && (!$p || (int)$p['estudiante_id']!==$student || $p['estado']==='ANULADO')){throw new \DomainException('El préstamo no corresponde al estudiante.');}
            if($type==='MORA'){
                if(!$p || strtotime($p['fecha_devolucion']??'now')-strtotime($p['fecha_limite'])<=86400){throw new \DomainException('La mora requiere un préstamo con retraso superior a 24 horas.');}
                if(!str_contains(mb_strtolower($reason),'política') && !str_contains(mb_strtolower($reason),'reglamento')){throw new \DomainException('Documenta la política o reglamento institucional que autoriza esta decisión.');}
            }
            DB::run('INSERT INTO sanciones(estudiante_id,prestamo_id,registrada_por,tipo,motivo) VALUES(?,?,?,?,?)',[$student,$loan,$user['id'],$type,$reason]);Audit::record('REGISTRAR_SANCION','sanciones',(int)DB::get()->lastInsertId(),'Decisión manual sin tarifa automática');
        });
    }
    public static function resolve(array $in,array $user): void {
        S::admin($user);$id=S::id($in['id']??0);$notes=S::text($in['observaciones']??'',5000);$state=$in['estado']??'';
        if(!in_array($state,['RESUELTA','ANULADA'],true)){throw new \DomainException('Estado inválido.');}
        DB::transaction(function() use($id,$notes,$state): void {if(!DB::run("UPDATE sanciones SET estado=?,fecha_resolucion=NOW(),observaciones_resolucion=? WHERE id=? AND estado='ABIERTA'",[$state,$notes,$id])){throw new \DomainException('Sanción inexistente o cerrada.');}Audit::record('RESOLVER_SANCION','sanciones',$id,$notes);});
    }
}
