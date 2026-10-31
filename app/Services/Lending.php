<?php
declare(strict_types=1);
namespace Sipe\Services;
use Sipe\Models\Database as DB;
use Sipe\Middleware\Security as S;
final class Lending
{
    public static function dates(string $start,string $end,bool $future=true): array {
        $parse=static function(string $v): \DateTimeImmutable { $d=\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$v); if(!$d || $d->format('Y-m-d\\TH:i')!==$v){throw new \DomainException('Fecha u hora inválida.');} return $d; };
        $a=$parse($start); $b=$parse($end);
        if($b<=$a || ($future && $a<new \DateTimeImmutable('-1 minute'))){throw new \DomainException('Elige un intervalo futuro con final posterior al inicio.');}
        return [$a->format('Y-m-d H:i:s'),$b->format('Y-m-d H:i:s')];
    }
    private static function eligible(int $student,int $resource): array {
        // Orden común de bloqueos: estudiante → recurso → movimientos.
        $s=DB::one('SELECT * FROM estudiantes WHERE id=? FOR UPDATE',[$student]);
        $r=DB::one('SELECT * FROM recursos WHERE id=? FOR UPDATE',[$resource]);
        if(!$s || !$r || !$s['activo'] || !$r['activo']){throw new \DomainException('Estudiante o recurso inactivo o inexistente.');}
        $p=$s['programa_id']?DB::one('SELECT activo FROM programas_academicos WHERE id=? LOCK IN SHARE MODE',[$s['programa_id']]):null;
        $c=DB::one('SELECT activo FROM categorias_recursos WHERE id=? LOCK IN SHARE MODE',[$r['categoria_id']]);
        if(($s['tipo_persona']==='ESTUDIANTE' && (!$p || !$p['activo'])) || !$c['activo'] || $r['estado_operativo']!=='DISPONIBLE' || $r['estado_fisico']==='DANADO'){throw new \DomainException('El recurso o programa no está habilitado.');}
        if($s['tipo_persona']==='ESTUDIANTE' && !DB::one('SELECT programa_id FROM autorizaciones_programa_categoria WHERE programa_id=? AND categoria_id=? LOCK IN SHARE MODE',[$s['programa_id'],$r['categoria_id']])){throw new \DomainException('El programa no está autorizado para esta categoría.');}
        if(DB::one("SELECT id FROM prestamos WHERE recurso_id=? AND estado='ACTIVO'",[$resource])){throw new \DomainException('El recurso tiene un préstamo activo.');}
        return $r;
    }
    private static function conflicts(int $resource,string $a,string $b,?int $except=null): void {
        $sql="SELECT id FROM reservas WHERE recurso_id=? AND estado='CONFIRMADA' AND inicio<? AND fin>?"; $params=[$resource,$b,$a];
        if($except){$sql.=' AND id<>?';$params[]=$except;}
        if(DB::one($sql,$params)){throw new \DomainException('El horario se cruza con una reserva confirmada.');}
    }
    public static function reserve(array $in,int $user): int {
        $student=S::id($in['estudiante_id']??0);$resource=S::id($in['recurso_id']??0);[$type,$group]=LoanPolicy::options($in);[$a,$b]=LoanPolicy::interval($in,(string)($in['inicio']??''));
        return DB::transaction(function() use($student,$resource,$a,$b,$user,$type,$group): int { self::eligible($student,$resource);$person=DB::one('SELECT tipo_persona FROM estudiantes WHERE id=?',[$student])['tipo_persona'];if($group!==$person){throw new \DomainException('El grupo debe coincidir con la ficha del prestatario.');}self::conflicts($resource,$a,$b);$state=$person==='ESTUDIANTE'?'CONFIRMADA':'PENDIENTE_APROBACION';DB::run('INSERT INTO reservas(estudiante_id,recurso_id,creada_por,inicio,fin,tipo_prestamo,grupo_trabajo,estado) VALUES(?,?,?,?,?,?,?,?)',[$student,$resource,$user,$a,$b,$type,$group,$state]); $id=(int)DB::get()->lastInsertId();Audit::record($state==='CONFIRMADA'?'RESERVAR':'SOLICITAR_APROBACION','reservas',$id,$type);return $id; });
    }
    public static function preview(array $in): array {
        $student=S::id($in['estudiante_id']??0);$resource=S::id($in['recurso_id']??0);
        [$a,$b]=LoanPolicy::interval($in,(string)($in['inicio']??''));
        [$type,$group]=LoanPolicy::options($in);
        DB::transaction(function()use($student,$resource,$a,$b,$group): void {self::eligible($student,$resource);if(DB::one('SELECT tipo_persona FROM estudiantes WHERE id=?',[$student])['tipo_persona']!==$group){throw new \DomainException('El grupo no coincide con la ficha.');}self::conflicts($resource,$a,$b);});
        return [$a,$b];
    }
    public static function lend(array $in,int $user): int {
        $student=S::id($in['estudiante_id']??0);$resource=S::id($in['recurso_id']??0); $reservation=empty($in['reserva_id'])?null:S::id($in['reserva_id']);
        [$type,$group]=LoanPolicy::options($in);
        $a=date('Y-m-d H:i:s'); [, $b]=LoanPolicy::interval($in,date('Y-m-d\\TH:i'),false);
        if($b<=$a){throw new \DomainException('El plazo debe ser posterior a la entrega.');}
        $physical=S::text($in['estado_fisico']??'',10);self::physical($physical);
        if($physical==='DANADO'){throw new \DomainException('Un recurso dañado no puede entregarse.');}
        $notes=S::text($in['observaciones']??'',5000,false);
        return DB::transaction(function() use($student,$resource,$reservation,$a,$b,$physical,$notes,$user,$type,$group): int {
            self::eligible($student,$resource);
            $person=DB::one('SELECT tipo_persona FROM estudiantes WHERE id=?',[$student])['tipo_persona'];
            if($group!==$person){throw new \DomainException('El grupo debe coincidir con la ficha del prestatario.');}
            if($reservation){ $q=DB::one('SELECT * FROM reservas WHERE id=? FOR UPDATE',[$reservation]); if(!$q || (int)$q['estudiante_id']!==$student || (int)$q['recurso_id']!==$resource || $q['estado']!=='CONFIRMADA' || $q['inicio']>$a || $q['fin']<=$a || $b>$q['fin']){throw new \DomainException('La reserva no coincide o está fuera de su horario.');} }
            if($reservation && (($q['tipo_prestamo']!==null && $q['tipo_prestamo']!==$type) || ($q['grupo_trabajo']!==null && $q['grupo_trabajo']!==$group))){throw new \DomainException('El tipo y grupo deben coincidir con la reserva.');}
            if($person!=='ESTUDIANTE' && (!$reservation || !$q['aprobado_por'])){throw new \DomainException('Este prestatario requiere aprobación manual del administrador para cada préstamo.');}
            self::conflicts($resource,$a,$b,$reservation);
            DB::run('INSERT INTO prestamos(estudiante_id,recurso_id,reserva_id,entregado_por,fecha_entrega,fecha_limite,observaciones_entrega,tipo_prestamo,grupo_trabajo) VALUES(?,?,?,?,?,?,?,?,?)',[$student,$resource,$reservation,$user,$a,$b,$notes,$type,$group]);$id=(int)DB::get()->lastInsertId();
            DB::run("UPDATE recursos SET estado_operativo='PRESTADO',estado_fisico=? WHERE id=?",[$physical,$resource]);
            DB::run("INSERT INTO inspecciones(recurso_id,prestamo_id,inspeccionado_por,momento,estado_fisico,apto_para_prestamo,observaciones) VALUES(?,?,?,'ANTES_ENTREGA',?,1,?)",[$resource,$id,$user,$physical,$notes]);
            if($reservation){DB::run("UPDATE reservas SET estado='ATENDIDA' WHERE id=?",[$reservation]);} Audit::record('ENTREGAR','prestamos',$id);return $id;
        });
    }
    public static function physical(string $value): void { if(!in_array($value,['BUENO','REGULAR','DANADO'],true)){throw new \DomainException('Estado físico inválido.');} }
    public static function approve(int $id,string $reason,array $user): void {
        S::admin($user);$reason=S::text($reason,1000);$initial=DB::one('SELECT estudiante_id,recurso_id FROM reservas WHERE id=?',[$id]);if(!$initial){throw new \DomainException('Solicitud inexistente.');}
        DB::transaction(function()use($id,$reason,$user,$initial): void {
            self::eligible((int)$initial['estudiante_id'],(int)$initial['recurso_id']);
            $q=DB::one('SELECT * FROM reservas WHERE id=? FOR UPDATE',[$id]);
            $person=DB::one('SELECT tipo_persona FROM estudiantes WHERE id=?',[$q['estudiante_id']])['tipo_persona'];
            if($q['estado']!=='PENDIENTE_APROBACION' || $q['fin']<=date('Y-m-d H:i:s') || $q['grupo_trabajo']!==$person){throw new \DomainException('La solicitud no admite aprobación. Revisa su vigencia y grupo.');}
            self::conflicts((int)$q['recurso_id'],$q['inicio'],$q['fin']);
            DB::run("UPDATE reservas SET estado='CONFIRMADA',aprobado_por=?,motivo_aprobacion=? WHERE id=?",[$user['id'],$reason,$id]);Audit::record('APROBAR_PRESTAMO','reservas',$id,$reason);
        });
    }
    public static function returnLoan(array $in,int $user): void {
        $id=S::id($in['prestamo_id']??0);$state=S::text($in['estado_fisico']??'',10);self::physical($state);$notes=S::text($in['observaciones']??'',5000,false);$fit=($in['apto']??'0')==='1' && $state!=='DANADO';
        $initial=DB::one('SELECT estudiante_id,recurso_id FROM prestamos WHERE id=?',[$id]);if(!$initial){throw new \DomainException('Préstamo inexistente.');}
        DB::transaction(function() use($initial,$id,$state,$notes,$fit,$user): void {
            DB::one('SELECT id FROM estudiantes WHERE id=? FOR UPDATE',[$initial['estudiante_id']]);DB::one('SELECT id FROM recursos WHERE id=? FOR UPDATE',[$initial['recurso_id']]);
            $p=DB::one('SELECT * FROM prestamos WHERE id=? FOR UPDATE',[$id]);if($p['estado']!=='ACTIVO'){throw new \DomainException('El préstamo ya fue cerrado.');}
            DB::run('INSERT INTO devoluciones(prestamo_id,recibido_por,estado_retorno,observaciones) VALUES(?,?,?,?)',[$id,$user,$state,$notes]);DB::run("UPDATE prestamos SET estado='DEVUELTO' WHERE id=?",[$id]);
            DB::run('UPDATE recursos SET estado_operativo=?,estado_fisico=? WHERE id=?',[$fit?'DISPONIBLE':'MANTENIMIENTO',$state,$p['recurso_id']]);
            DB::run("INSERT INTO inspecciones(recurso_id,prestamo_id,inspeccionado_por,momento,estado_fisico,apto_para_prestamo,observaciones) VALUES(?,?,?,'EN_DEVOLUCION',?,?,?)",[$p['recurso_id'],$id,$user,$state,(int)$fit,$notes]);
            $late=time()-strtotime($p['fecha_limite'])>86400; Audit::record('DEVOLVER','prestamos',$id,$late?'Retraso superior a 24 h: requiere revisión administrativa, sin multa automática.':'Devolución registrada');
        });
    }
    public static function cancel(int $id): void {
        $initial=DB::one('SELECT estudiante_id,recurso_id FROM reservas WHERE id=?',[$id]);if(!$initial){throw new \DomainException('Reserva inexistente.');}
        DB::transaction(function() use($id,$initial): void { DB::one('SELECT id FROM estudiantes WHERE id=? FOR UPDATE',[$initial['estudiante_id']]);DB::one('SELECT id FROM recursos WHERE id=? FOR UPDATE',[$initial['recurso_id']]); if(!DB::run("UPDATE reservas SET estado='CANCELADA' WHERE id=? AND estado IN ('CONFIRMADA','PENDIENTE_APROBACION')",[$id])){throw new \DomainException('La reserva no se puede cancelar.');} Audit::record('CANCELAR','reservas',$id); });
    }
    public static function inspect(array $in,int $user): void {
        $resource=S::id($in['recurso_id']??0);$state=S::text($in['estado_fisico']??'',10);self::physical($state);$notes=S::text($in['observaciones']??'',5000,false);$fit=($in['apto']??'0')==='1' && $state!=='DANADO';
        DB::transaction(function() use($resource,$state,$notes,$fit,$user): void {
            $r=DB::one('SELECT * FROM recursos WHERE id=? FOR UPDATE',[$resource]);if(!$r){throw new \DomainException('Recurso inexistente.');}
            DB::run("INSERT INTO inspecciones(recurso_id,inspeccionado_por,momento,estado_fisico,apto_para_prestamo,observaciones) VALUES(?,?,'PERIODICA',?,?,?)",[$resource,$user,$state,(int)$fit,$notes]); $id=(int)DB::get()->lastInsertId();
            // Nunca libera un recurso prestado ni reactiva una baja desde una inspección.
            $op=in_array($r['estado_operativo'],['PRESTADO','BAJA'],true)?$r['estado_operativo']:($fit?'DISPONIBLE':'MANTENIMIENTO');
            DB::run('UPDATE recursos SET estado_fisico=?,estado_operativo=? WHERE id=?',[$state,$op,$resource]); Audit::record('INSPECCIONAR','inspecciones',$id);
        });
    }
}
