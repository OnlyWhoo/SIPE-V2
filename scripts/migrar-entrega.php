<?php
declare(strict_types=1);

// Consola del responsable de la instalación. El paquete del profesor usa el instalador web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
ini_set('display_errors','0');
require dirname(__DIR__).'/app/bootstrap.php';

use Sipe\Installation\{Entrega,Local,Schema};

function removeDemo(PDO $db): void
{
    // Ambas referencias y la marca del movimiento deben corresponder a la semilla anterior.
    $demoPerson = "e.codigo_estudiante IN ('DEMO-001','DEMO-002','DEMO-EGR','DEMO-ADM')
        AND e.correo LIKE '%@example.invalid'";
    $demoResource = "r.codigo_inventario IN ('DEMO-CAM-01','DEMO-TAB-01','DEMO-TRI-01','DEMO-CAM-02','DEMO-MIC-01')";
    $loans = $db->query("SELECT p.id FROM prestamos p JOIN estudiantes e ON e.id=p.estudiante_id
        JOIN recursos r ON r.id=p.recurso_id WHERE $demoPerson AND $demoResource
        AND p.observaciones_entrega LIKE 'DEMO:%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($loans as $id) {
        foreach ([
            "DELETE FROM inspecciones WHERE prestamo_id=? AND observaciones='DEMO'",
            "DELETE FROM devoluciones WHERE prestamo_id=? AND observaciones LIKE 'DEMO:%'",
            "DELETE FROM sanciones WHERE prestamo_id=? AND motivo LIKE 'DEMO:%'",
            'DELETE FROM prestamos WHERE id=?',
        ] as $sql) {
            $db->prepare($sql)->execute([$id]);
        }
    }
    $db->exec("DELETE q FROM reservas q JOIN estudiantes e ON e.id=q.estudiante_id JOIN recursos r ON r.id=q.recurso_id
        WHERE $demoPerson AND $demoResource AND q.observaciones LIKE 'DEMO:%'");
    $db->exec("DELETE e FROM estudiantes e WHERE $demoPerson");
    $db->exec("DELETE r FROM recursos r WHERE $demoResource AND r.nombre LIKE '% DEMO'");
    $db->exec("DELETE a FROM autorizaciones_programa_categoria a
        JOIN programas_academicos p ON p.id=a.programa_id
        JOIN categorias_recursos c ON c.id=a.categoria_id
        WHERE p.codigo IN ('DEMO-DIS','DEMO-MUL') AND p.nombre IN ('Diseño DEMO','Multimedia DEMO')
        AND c.nombre IN ('Cámaras DEMO','Accesorios DEMO')");
    $db->exec("DELETE FROM programas_academicos WHERE codigo IN ('DEMO-DIS','DEMO-MUL') AND nombre IN ('Diseño DEMO','Multimedia DEMO')");
    $db->exec("DELETE FROM categorias_recursos WHERE nombre IN ('Cámaras DEMO','Accesorios DEMO')");
}

function migrateSchema(PDO $db, string $name, array $old, array $new): void
{
    $changes = explode(';',(string)file_get_contents(ROOT.'/database/source/migrations/003_entrega_academica.sql'));
    foreach (['estudiantes','fotografias_prestatarios','recursos'] as $index=>$table) {
        $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
        $exists->execute([$name,$table]);
        if ((int)$exists->fetchColumn() === 0) {
            if (isset($old['tables'][$table])) {
                throw new RuntimeException('Falta una tabla de la instalación anterior.');
            }
            $db->exec($changes[$index]);
        } elseif (Schema::signature($db,$name,$table) !== $new['tables'][$table]['signature']) {
            Schema::table($db,$name,$table,$old['tables'][$table]['signature']??[]);
            $db->exec($changes[$index]);
        }
    }
    Schema::verify($db,$name,$new);
}

$lock = null;
$db = null;
$phase = 'validación';
try {
    [$command,$mode,$configPath,$dataDirectory,$backup,$expectedSha] = $argv + array_fill(0,7,null);
    $testName = $argv[6] ?? '';
    if (!in_array($mode,['--copia','--activa'],true)
        || !is_file($configPath??'') || !is_file($backup??'')
        || !preg_match('/\A[a-f0-9]{64}\z/i',$expectedSha??'')
        || !hash_equals(strtolower($expectedSha),hash_file('sha256',$backup))) {
        throw new RuntimeException('Indica la configuración privada y un respaldo restaurado y verificado por SHA-256.');
    }
    $config = require $configPath;
    if (!preg_match('/\Amysql:host=(?:127\.0\.0\.1|localhost);port=3306;dbname=(sipe_v2(?:_[a-zA-Z0-9]+)*);charset=utf8mb4\z/D',$config['dsn'],$match)) {
        throw new RuntimeException('La configuración no corresponde a MariaDB de XAMPP en 3306.');
    }
    $name = $match[1];
    if ($mode === '--copia') {
        if (!preg_match('/\Asipe_v2_copia_entrega_[0-9]+\z/D',$testName) || $testName === $name) {
            throw new RuntimeException('Indica una copia de ensayo con nombre sipe_v2_copia_entrega_.');
        }
        $name = $testName;
        $config['dsn'] = str_replace('dbname='.$match[1].';','dbname='.$name.';',$config['dsn']);
    } else {
        $lock = fopen(dirname($configPath).'/migration.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {
            throw new RuntimeException('Hay una solicitud abierta en la instalación. Reintenta la migración.');
        }
    }
    $db = new PDO($config['dsn'],$config['user'],$config['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $oldPath = ROOT.'/database/install-plan.json';
    $newPath = ROOT.'/database/entrega-plan.json';
    $oldDigest = hash_file('sha256',$oldPath);
    $newDigest = hash_file('sha256',$newPath);
    $old = Entrega::read($oldPath);
    $new = Entrega::read($newPath);
    $marker = $db->query('SELECT owner,plan,phase FROM sipe_v2_installation WHERE id=1')->fetch();
    if (!$marker || !hash_equals($config['owner']??'', $marker['owner']) ||
        !in_array($marker['plan'],[$oldDigest,$newDigest],true) ||
        !in_array($marker['phase'],['ready','migrating'],true)) {
        throw new RuntimeException('El origen de la instalación o su marcador requiere revisión.');
    }
    $pdoVersion = $db->getAttribute(PDO::ATTR_SERVER_VERSION);
    if (!str_contains($pdoVersion,'MariaDB') || version_compare($pdoVersion,'10.4','<')) {
        throw new RuntimeException('La migración requiere MariaDB de XAMPP compatible.');
    }
    $db->exec("SET time_zone='-05:00'");
    $db->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $private = Entrega::read($dataDirectory.'/entrega-privada.json');
    $catalog = Entrega::read($dataDirectory.'/inventario-entrega.json');
    $rotation = Entrega::read($dataDirectory.'/acceso-local-hash.json');
    if ($marker['plan'] === $oldDigest) {
        $phase = 'esquema';
        if ($marker['phase'] === 'ready') {
            Schema::verify($db,$name,$old);
            $db->exec("UPDATE sipe_v2_installation SET phase='migrating' WHERE id=1");
        }
        migrateSchema($db,$name,$old,$new);
        $phase = 'datos y dependencias';
        $db->beginTransaction();
        removeDemo($db);
        $db->prepare('DELETE FROM estudiantes WHERE codigo_estudiante=?')->execute([$private['codigo_excluido']]);
        $db->prepare("UPDATE usuarios SET nombre_completo='Administrador SIPE',password_hash=?
            WHERE correo='profesor@sipe.local' AND nombre_completo='Profesor DEMO'
            AND rol_id=(SELECT id FROM roles WHERE nombre='ADMINISTRADOR')")->execute([$rotation['hash']]);
        Entrega::import($db,$catalog,$private);
        $step = $db->prepare('INSERT INTO sipe_v2_steps(id,checksum) VALUES(?,?) ON DUPLICATE KEY UPDATE checksum=VALUES(checksum)');
        foreach ($new['tables'] as $table=>$entry) {
            $step->execute([$table,hash('sha256',$entry['sql'])]);
        }
        $db->prepare("UPDATE sipe_v2_installation SET plan=?,phase='ready',version=? WHERE id=1")
            ->execute([$newDigest,$new['version']]);
        $db->exec("INSERT INTO auditoria(accion,entidad,detalle)
            VALUES('MIGRAR_ENTREGA','sipe_v2_installation','Actualización de datos académicos con respaldo verificado')");
        $db->commit();
    } else {
        Schema::verify($db,$name,$new);
    }
    if ($mode === '--activa' && ($config['plan']??'') !== $newDigest) {
        $phase = 'configuración privada';
        unset($config['demo_local']);
        $config['plan'] = $newDigest;
        Local::write($configPath,"<?php\nreturn ".var_export($config,true).";\n");
    }
    echo "Migración verificada en {$name}.\n";
} catch (Throwable $e) {
    if ($db && $db->inTransaction()) { $db->rollBack(); }
    $reason = $e instanceof PDOException ? 'SQLSTATE '.$e->getCode() : ($e instanceof RuntimeException ? $e->getMessage() : get_class($e));
    fwrite(STDERR,"Migración detenida durante $phase. $reason\n");
    exit(1);
} finally {
    if (is_resource($lock)) { fclose($lock); }
}
