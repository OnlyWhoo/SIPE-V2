<?php
declare(strict_types=1);

// Compara el padrón y el inventario con los datos de origen. Imprime solo totales.
require dirname(__DIR__).'/app/bootstrap.php';
use Sipe\Installation\{Entrega,Installer,Schema};

function verify(bool $condition,string $description): void
{
    if (!$condition) { throw new RuntimeException($description); }
}

try {
    $name = $argv[1]??'';
    $private = $argv[2]??'';
    verify((bool)preg_match('/\Asipe_v2(?:_[a-zA-Z0-9]+)*\z/D',$name),'Nombre de base inválido');
    $db = Installer::connect(['host'=>'127.0.0.1','port'=>'3306','user'=>getenv('SIPE_TEST_DB_USER')?:'root',
        'password'=>getenv('SIPE_TEST_DB_PASSWORD')?:'']);
    $db->exec('USE `'.$name.'`');
    Schema::verify($db,$name,Entrega::read(ROOT.'/database/entrega-plan.json'));
    $source = Entrega::read($private.'/entrega-privada.json');
    $inventory = Entrega::read($private.'/inventario-entrega.json');
    $findPerson = $db->prepare('SELECT e.*,p.nombre AS programa,f.origen AS foto_origen,
        SHA2(f.contenido,256) AS foto_sha FROM estudiantes e LEFT JOIN programas_academicos p ON p.id=e.programa_id
        LEFT JOIN fotografias_prestatarios f ON f.estudiante_id=e.id WHERE e.codigo_estudiante=?');
    foreach ($source['personas'] as $person) {
        $findPerson->execute([$person['codigo']]);
        $row = $findPerson->fetch();
        verify($row && $row['codigo_estudiante']===$person['codigo'] && $row['cedula']===$person['cedula']
            && $row['nombres']===$person['nombres'] && $row['apellidos']===$person['apellidos']
            && $row['correo']===Entrega::email($person['codigo']) && $row['tipo_persona']===$person['rol']
            && $row['programa']===$person['programa'] && (bool)$row['activo']
            && $row['carnet_vigencia']===Entrega::VIGENCIA && $row['carnet_origen']===Entrega::ORIGEN
            && (bool)$row['clasificacion_pendiente']===$person['rol_pendiente']
            && (bool)$row['programa_provisional']===$person['programa_provisional']
            && $row['qr_token']===$person['qr'],'Diferencia de persona en fila '.$person['fila']);
        verify($person['foto']===null ? $row['foto_sha']===null :
            ($row['foto_origen']==='ILUSTRATIVA' && $row['foto_sha']===hash('sha256',base64_decode($person['foto'],true))),
            'Diferencia de fotografía en fila '.$person['fila']);
    }
    $ids = $db->prepare('SELECT COUNT(*) FROM estudiantes WHERE codigo_estudiante=?');
    $ids->execute([$source['codigo_excluido']]);
    verify((int)$ids->fetchColumn()===0,'La base contiene el código excluido');
    $find = $db->prepare('SELECT r.*,c.nombre AS categoria FROM recursos r
        JOIN categorias_recursos c ON c.id=r.categoria_id WHERE r.codigo_inventario=?');
    foreach ($inventory['recursos'] as $resource) {
        $find->execute([$resource['referencia']]);
        $row = $find->fetch();
        verify($row && $row['codigo_inventario']===$resource['referencia'] && $row['nombre']===$resource['nombre']
            && $row['categoria']===$resource['tipo'] && ($row['descripcion']??'')===$resource['observaciones']
            && $row['estado_operativo']==='DISPONIBLE' && (bool)$row['activo'],
            'Diferencia de recurso en fila '.$resource['fila']);
    }
    foreach ($inventory['referencias_pendientes'] as $pending) {
        $find->execute([$pending['referencia']]);
        verify(!$find->fetch(),'Se importó una referencia pendiente');
    }
    foreach (['estudiantes'=>123,'fotografias_prestatarios'=>123,'recursos'=>150,'reservas'=>0,'prestamos'=>0,
        'devoluciones'=>0,'inspecciones'=>0,'sanciones'=>0] as $table=>$count) {
        verify((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count,
            'Conteo inesperado en '.$table);
    }
    verify((int)$db->query("SELECT COUNT(*) FROM estudiantes WHERE codigo_estudiante LIKE 'DEMO-%'
        OR correo LIKE '%@example.invalid'")->fetchColumn()===0,'Prestatario ficticio presente');
    verify((int)$db->query('SELECT COUNT(DISTINCT qr_token) FROM estudiantes')->fetchColumn()===123,
        'Token duplicado');
    verify((int)$db->query('SELECT COUNT(DISTINCT SHA2(contenido,256)) FROM fotografias_prestatarios')->fetchColumn()===123,
        'Fotografía duplicada');
    echo "OK 123 fichas, 123 correos, 123 QR, 123 fotografías y 150 recursos contrastados campo a campo.\n";
    echo "OK 11 referencias ausentes del catálogo operativo, exclusión confirmada y cero movimientos precargados.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'FALLO '.($error instanceof PDOException?'SQLSTATE '.$error->getCode():$error->getMessage())."\n");
    exit(1);
}
