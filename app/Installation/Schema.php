<?php
declare(strict_types=1);
namespace Sipe\Installation;
use PDO;
use RuntimeException;

final class Schema
{
    public static function expression(?string $value): ?string {
        if ($value===null || strtoupper($value)==='NULL') { return null; }
        return strtolower((string)preg_replace('/[\s`()]+|_utf8mb4|_utf8mb3/u','',$value));
    }
    public static function signature(PDO $pdo,string $database,string $table): array {
        $q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$q->execute([$database,$table]);
        $engine=$q->fetchColumn();
        if($engine===false){throw new RuntimeException('Falta una tabla del esquema: '.$table);}
        $q=$pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');$q->execute([$database,$table]);$columns=[];
        foreach($q as $c){
            $type=preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i','$1',$c['COLUMN_TYPE']);
            $default=$c['COLUMN_DEFAULT'];
            if($default!==null && strlen($default)>=2 && $default[0]==="'" && substr($default,-1)==="'"){$default=substr($default,1,-1);}
            $extra=trim(str_replace('default_generated','',strtolower($c['EXTRA'])));
            $columns[]=[$c['COLUMN_NAME'],strtolower($type),$c['IS_NULLABLE'],self::expression($default),$extra,self::expression($c['GENERATION_EXPRESSION']?:null),$c['COLLATION_NAME']];
        }
        $q=$pdo->query('SHOW INDEX FROM `'.$table.'`');$indexes=[];
        foreach($q as $r){$indexes[$r['Key_name']]['unique']=!(bool)$r['Non_unique'];$indexes[$r['Key_name']]['columns'][(int)$r['Seq_in_index']]=[$r['Column_name'],$r['Sub_part']===null?null:(int)$r['Sub_part']];}
        foreach($indexes as &$index){ksort($index['columns']);$index['columns']=array_values($index['columns']);}unset($index);ksort($indexes);
        $q=$pdo->prepare('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=? AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION');$q->execute([$database,$table]);$foreign=[];
        foreach($q as $r){$foreign[]=[$r['CONSTRAINT_NAME'],$r['COLUMN_NAME'],$r['REFERENCED_TABLE_SCHEMA']===$database,$r['REFERENCED_TABLE_NAME'],$r['REFERENCED_COLUMN_NAME'],$r['UPDATE_RULE'],$r['DELETE_RULE']];}
        $maria=stripos((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')!==false;
        $q=$pdo->prepare("SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME".($maria?' AND c.TABLE_NAME=t.TABLE_NAME':'')." WHERE t.TABLE_SCHEMA=? AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE='CHECK'");$q->execute([$database,$table]);
        $checks=array_map([self::class,'expression'],$q->fetchAll(PDO::FETCH_COLUMN));sort($checks);
        return ['engine'=>strtolower((string)$engine),'columns'=>$columns,'indexes'=>$indexes,'foreign'=>$foreign,'checks'=>$checks];
    }
    public static function table(PDO $pdo,string $database,string $table,array $expected): void {
        if(self::signature($pdo,$database,$table)!==$expected){throw new RuntimeException('El esquema existente no coincide en '.$table.'. Se detuvo sin reconstruir ni borrar datos.');}
    }
    public static function view(PDO $pdo,string $database,string $name,string $expected): void {
        $q=$pdo->prepare('SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$q->execute([$database,$name]);$actual=$q->fetchColumn();
        if($actual===false || self::viewText((string)$actual,$database)!==self::viewText($expected,'sipe_v2_template')){throw new RuntimeException('La vista de préstamos no coincide con la edición.');}
    }
    public static function viewText(string $text,string $database): string {
        $text=str_replace(['`'.$database.'`.',$database.'.'],'',$text);
        return self::expression($text)??'';
    }
    public static function verify(PDO $pdo,string $database,array $plan): void {
        foreach($plan['tables'] as $name=>$table){self::table($pdo,$database,$name,$table['signature']);}
        self::view($pdo,$database,$plan['view']['name'],$plan['view']['definition']);
        $q=$pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=?');$q->execute([$database]);$actual=$q->fetchAll(PDO::FETCH_COLUMN);
        $expected=[...array_keys($plan['tables']),$plan['view']['name'],'sipe_v2_installation','sipe_v2_steps'];sort($actual);sort($expected);
        if($actual!==$expected){throw new RuntimeException('La base contiene objetos inesperados. No se modificaron.');}
    }
}
