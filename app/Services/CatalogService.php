<?php
declare(strict_types=1);
namespace Sipe\Services;
use Sipe\Models\Database as DB;
use Sipe\Models\Catalog;
use Sipe\Middleware\Security as S;
use Sipe\Installation\Entrega;
final class CatalogService
{
    public static function save(string $table,array $input,array $user,array $profile=[]): int {
        $def=Catalog::definition($table);
        if($table==='estudiantes'){$input['tipo_persona']??='ESTUDIANTE';if($input['tipo_persona']!=='ESTUDIANTE'){S::admin($user);}}
        if($table==='estudiantes' && Profiles::enabled() && is_string($input['codigo_estudiante']??null)
            && ctype_digit(trim($input['codigo_estudiante']))){
            $input['correo']=Entrega::email(trim($input['codigo_estudiante']));
        }
        if ($table!=='estudiantes') { S::admin($user); }
        $id=empty($input['id'])?null:S::id($input['id']); $values=[];
        $limits=['codigo_estudiante'=>40,'nombres'=>100,'apellidos'=>100,'correo'=>180,'codigo'=>30,'nombre'=>150,'codigo_inventario'=>50,'nombre_completo'=>150,'descripcion'=>5000];
        if($table==='recursos'){$limits['nombre']=255;}
        foreach($def['fields'] as $field=>$label) {
            if($field==='password') { if(!empty($input[$field])){S::password((string)$input[$field]);$values['password_hash']=password_hash($input[$field],PASSWORD_DEFAULT);} elseif(!$id){throw new \DomainException('La contraseña es obligatoria.');} continue; }
            $value=S::text($input[$field]??'', $limits[$field]??100,in_array($field,$def['required'],true));
            if($field==='correo' && $value!==''){$value=S::email($value);}
            $options=Catalog::options($field);
            if($options!==null && !($field==='programa_id' && $value==='' && ($input['tipo_persona']??'ESTUDIANTE')!=='ESTUDIANTE') && !array_key_exists($value,$options)){throw new \DomainException("Selecciona un valor válido para $label.");}
            $values[$field]=$value===''?null:$value;
        }
        if($table==='categorias_recursos' && mb_strlen($values['nombre'])>100){throw new \DomainException('La categoría admite 100 caracteres.');}
        $photo=null;
        if($table==='estudiantes' && Profiles::enabled() && $profile){
            S::admin($user);
            $values+=Profiles::fields($profile);
            $photo=Profiles::upload($profile['fotografia']??null);
        }
        return DB::transaction(function() use($table,$id,$values,$user,$photo): int {
            if($id){
                $old=DB::one("SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]); if(!$old){throw new \DomainException('Registro no encontrado.',404);}
                if($table==='estudiantes' && $old['tipo_persona']!==$values['tipo_persona']){S::admin($user);if(DB::one("SELECT id FROM reservas WHERE estudiante_id=? AND estado IN ('CONFIRMADA','PENDIENTE_APROBACION') AND fin>NOW()",[$id]) || DB::one("SELECT id FROM prestamos WHERE estudiante_id=? AND estado='ACTIVO'",[$id])){throw new \DomainException('Cierra las operaciones antes de cambiar el grupo del prestatario.');}}
                if($table==='recursos'){
                    if(DB::one("SELECT id FROM prestamos WHERE recurso_id=? AND estado='ACTIVO'",[$id]) || DB::one("SELECT id FROM reservas WHERE recurso_id=? AND estado='CONFIRMADA' AND fin>NOW()",[$id])){throw new \DomainException('El recurso tiene operaciones pendientes. No se modifica.');}
                }
                if($table==='usuarios' && (int)$id===(int)$user['id'] && (int)$values['rol_id']!==(int)$old['rol_id']){throw new \DomainException('No puedes cambiar tu propio rol.');}
                if($table==='estudiantes' && (int)$old['programa_id']!==(int)$values['programa_id'] && DB::one("SELECT id FROM reservas WHERE estudiante_id=? AND estado='CONFIRMADA' AND fin>NOW()",[$id])){throw new \DomainException('Cancela las reservas antes de cambiar de programa.');}
                if($table==='estudiantes' && Profiles::enabled()){
                    if(array_key_exists('carnet_vigencia',$values) && $values['carnet_vigencia']!==$old['carnet_vigencia']){
                        $values['carnet_origen']=$values['carnet_vigencia']===null?null:'ADMINISTRACION';
                    }
                    if($values['tipo_persona']==='DOCENTE' && ($old['tipo_persona']!=='DOCENTE' || (int)$old['programa_id']!==(int)$values['programa_id'])){
                        $values['programa_provisional']=(int)($values['programa_id']!==null);
                    }elseif($values['tipo_persona']!=='DOCENTE'){$values['programa_provisional']=0;}
                }
                $set=implode(',',array_map(fn($f)=>"$f=?",array_keys($values))); DB::run("UPDATE $table SET $set WHERE id=?",[...array_values($values),$id]);
            } else {
                if($table==='estudiantes'){
                    $values['qr_token']=bin2hex(random_bytes(32));
                    if(isset($values['carnet_vigencia'])){$values['carnet_origen']='ADMINISTRACION';}
                    if($values['tipo_persona']==='DOCENTE' && $values['programa_id']!==null){$values['programa_provisional']=1;}
                }
                $cols=implode(',',array_keys($values)); $marks=implode(',',array_fill(0,count($values),'?')); DB::run("INSERT INTO $table($cols) VALUES($marks)",array_values($values)); $id=(int)DB::get()->lastInsertId();
            }
            if($photo!==null){
                DB::run("INSERT INTO fotografias_prestatarios(estudiante_id,mime,contenido,origen)
                    VALUES(?,?,?,'ADMINISTRATIVA') ON DUPLICATE KEY UPDATE
                    mime=VALUES(mime),contenido=VALUES(contenido),origen=VALUES(origen)",[$id,...$photo]);
            }
            Audit::record('GUARDAR',$table,$id,'Campos administrativos actualizados'); return $id;
        });
    }
    public static function toggle(string $table,int $id,array $user): void {
        Catalog::definition($table); if($table!=='estudiantes'){S::admin($user);}
        DB::transaction(function() use($table,$id,$user): void {
            $old=DB::one("SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]); if(!$old){throw new \DomainException('Registro inexistente.');}
            if($table==='usuarios' && $id===(int)$user['id']){throw new \DomainException('No puedes desactivar tu propia cuenta.');}
            if(in_array($table,['recursos','estudiantes'],true)){
                $fk=$table==='recursos'?'recurso_id':'estudiante_id';
                if(DB::one("SELECT id FROM prestamos WHERE $fk=? AND estado='ACTIVO'",[$id]) || DB::one("SELECT id FROM reservas WHERE $fk=? AND estado='CONFIRMADA' AND fin>NOW()",[$id])){throw new \DomainException('Hay movimientos pendientes; ciérralos antes de desactivar.');}
            }
            DB::run("UPDATE $table SET activo=? WHERE id=?",[(int)!$old['activo'],$id]); Audit::record('CAMBIAR_VIGENCIA',$table,$id);
        });
    }
}
