<?php
declare(strict_types=1);
namespace Sipe\Services;
use Sipe\Models\Database as DB;
use Sipe\Middleware\Security as S;
final class LoanPolicy
{
    public static function closing(): string {
        $value=DB::one("SELECT valor FROM configuraciones WHERE clave='hora_limite_interno'")['valor']??'20:00';
        if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value)){throw new \DomainException('La hora límite interna necesita una configuración válida.');}
        return $value;
    }
    public static function groups(): array {
        return ['ESTUDIANTE','EGRESADO','ADMINISTRATIVO'];
    }
    public static function options(array $in): array {
        $type=S::text($in['tipo_prestamo']??'',10);
        if(!in_array($type,['INTERNO','EXTERNO'],true)){throw new \DomainException('Selecciona préstamo INTERNO o EXTERNO.');}
        $group=S::text($in['grupo_trabajo']??'ESTUDIANTE',100,false);
        if($group!=='' && !in_array($group,self::groups(),true)){throw new \DomainException('Selecciona un grupo de trabajo configurado.');}
        return [$type,$group===''?null:$group];
    }
    public static function interval(array $in,string $start,bool $future=true): array {
        [$type]=self::options($in);
        $end=(string)($in['fin']??'');
        if($type==='INTERNO'){
            $limit=substr($start,0,10).'T'.self::closing();
            if($start>=$limit){throw new \DomainException('No existe un intervalo interno válido: el inicio debe ser anterior al cierre de '.self::closing().'.');}
            if($end===''){$end=$limit;}
            if(substr($end,0,10)!==substr($start,0,10) || $end>$limit){throw new \DomainException('El préstamo interno debe finalizar el mismo día, como máximo a las '.self::closing().'.');}
        }elseif($end===''){throw new \DomainException('Selecciona la fecha y hora de devolución externa.');}
        return Lending::dates($start,$end,$future);
    }
}
