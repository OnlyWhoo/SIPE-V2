<?php
declare(strict_types=1);

namespace Sipe\Installation;

use PDO;
use RuntimeException;

final class Entrega
{
    public const VIGENCIA = '2026-12-31';
    public const ORIGEN = 'ENTREGA_ACADEMICA';

    public static function read(string $file): array
    {
        $text = @file_get_contents($file);
        if ($text === false) {
            throw new RuntimeException('Falta un archivo de instalación de SIPE V2.');
        }
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function email(string $code): string
    {
        if (!preg_match('/\A[0-9]{1,40}\z/D', $code)) {
            throw new \DomainException('El código institucional debe contener solo dígitos.');
        }
        return $code.'@fadp.edu.co';
    }

    public static function seed(PDO $db, array $admin, array $catalog, ?array $private): void
    {
        $db->exec("INSERT INTO roles(id,nombre,descripcion) VALUES
            (1,'ADMINISTRADOR','Gestión institucional'),(2,'EMPLEADO','Operación de préstamos')");
        $db->exec("INSERT INTO configuraciones(clave,valor,descripcion)
            VALUES('hora_limite_interno','20:00','Devolución interna máxima America/Bogota')");
        $db->prepare('INSERT INTO usuarios(rol_id,nombre_completo,correo,password_hash) VALUES(1,?,?,?)')
            ->execute([$admin['nombre'], $admin['correo'], $admin['hash']]);
        self::import($db, $catalog, $private);
    }

    public static function import(PDO $db, array $catalog, ?array $private): void
    {
        $programs = [];
        $findProgram = $db->prepare('SELECT id FROM programas_academicos WHERE nombre=?
            ORDER BY activo DESC,id LIMIT 1 FOR UPDATE');
        $insertProgram = $db->prepare('INSERT INTO programas_academicos(codigo,nombre) VALUES(?,?)');
        foreach ($catalog['programas'] as $name) {
            $findProgram->execute([$name]);
            $id = $findProgram->fetchColumn();
            if ($id === false) {
                $insertProgram->execute(['FADP-'.strtoupper(substr(hash('sha256',$name),0,16)), $name]);
                $id = $db->lastInsertId();
            }
            $programs[$name] = (int)$id;
        }

        self::inventory($db, $catalog['recursos']);
        if ($private === null) {
            return;
        }
        $roles = array_count_values(array_column($private['personas'], 'rol'));
        ksort($roles);
        if ($roles !== ['ADMINISTRATIVO'=>18,'DOCENTE'=>21,'ESTUDIANTE'=>84]) {
            throw new RuntimeException('El padrón no coincide con los totales autorizados.');
        }
        $find = $db->prepare('SELECT id,cedula,programa_id,tipo_persona FROM estudiantes
            WHERE codigo_estudiante=? FOR UPDATE');
        $insert = $db->prepare('INSERT INTO estudiantes(codigo_estudiante,cedula,nombres,apellidos,
            correo,tipo_persona,programa_id,carnet_vigencia,carnet_origen,clasificacion_pendiente,
            programa_provisional,qr_token) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $update = $db->prepare('UPDATE estudiantes SET cedula=?,nombres=?,apellidos=?,correo=?,tipo_persona=?,
            programa_id=?,carnet_vigencia=COALESCE(carnet_vigencia,?),
            carnet_origen=COALESCE(carnet_origen,?),clasificacion_pendiente=?,programa_provisional=? WHERE id=?');
        $movement = $db->prepare("SELECT EXISTS(SELECT 1 FROM prestamos WHERE estudiante_id=? AND estado='ACTIVO')
            OR EXISTS(SELECT 1 FROM reservas WHERE estudiante_id=? AND fin>NOW()
            AND estado IN ('CONFIRMADA','PENDIENTE_APROBACION'))");
        $photo = $db->prepare("INSERT INTO fotografias_prestatarios(estudiante_id,mime,contenido,origen)
            VALUES(?,'image/jpeg',?,'ILUSTRATIVA') ON DUPLICATE KEY UPDATE estudiante_id=VALUES(estudiante_id)");

        foreach ($private['personas'] as $person) {
            $code = $person['codigo'];
            if ($code === $private['codigo_excluido'] || !preg_match('/\A[0-9]{1,40}\z/D',$person['cedula'])
                || !preg_match('/\A[a-f0-9]{64}\z/D',$person['qr'])) {
                throw new RuntimeException('El padrón contiene un identificador inválido o excluido.');
            }
            $program = $person['programa'] === null ? null : $programs[$person['programa']];
            $values = [$person['cedula'],$person['nombres'],$person['apellidos'],self::email($code),
                $person['rol'],$program,self::VIGENCIA,self::ORIGEN,
                (int)$person['rol_pendiente'],(int)$person['programa_provisional']];
            $find->execute([$code]);
            $existing = $find->fetch();
            if ($existing) {
                if ($existing['cedula'] !== null && $existing['cedula'] !== $person['cedula']) {
                    throw new RuntimeException('Un código ya registrado tiene un documento distinto. Requiere revisión.');
                }
                $movement->execute([$existing['id'],$existing['id']]);
                if ($movement->fetchColumn() && ($existing['tipo_persona'] !== $person['rol']
                    || (int)$existing['programa_id'] !== (int)$program)) {
                    throw new RuntimeException('Un prestatario con operaciones vigentes cambiaría de rol o programa.');
                }
                $update->execute([...$values,$existing['id']]);
                $id = $existing['id'];
            } else {
                $insert->execute([$code,...$values,$person['qr']]);
                $id = $db->lastInsertId();
            }
            if ($person['foto'] !== null) {
                $bytes = base64_decode($person['foto'],true);
                if ($bytes === false || strlen($bytes) > 1_000_000
                    || (@getimagesizefromstring($bytes)['mime'] ?? '') !== 'image/jpeg') {
                    throw new RuntimeException('Una fotografía preparada no es un JPEG válido.');
                }
                $photo->execute([$id,$bytes]);
            }
        }
    }

    private static function inventory(PDO $db, array $resources): void
    {
        $findCategory = $db->prepare('SELECT id FROM categorias_recursos WHERE nombre=? FOR UPDATE');
        $insertCategory = $db->prepare('INSERT INTO categorias_recursos(nombre) VALUES(?)');
        $findResource = $db->prepare('SELECT id,categoria_id FROM recursos WHERE codigo_inventario=? FOR UPDATE');
        $insert = $db->prepare('INSERT INTO recursos(codigo_inventario,categoria_id,nombre,descripcion) VALUES(?,?,?,?)');
        $update = $db->prepare('UPDATE recursos SET categoria_id=?,nombre=?,descripcion=? WHERE id=?');
        $movement = $db->prepare("SELECT EXISTS(SELECT 1 FROM prestamos WHERE recurso_id=? AND estado='ACTIVO')
            OR EXISTS(SELECT 1 FROM reservas WHERE recurso_id=? AND fin>NOW()
            AND estado IN ('CONFIRMADA','PENDIENTE_APROBACION'))");
        $categories = [];
        foreach ($resources as $item) {
            $reference = $item['referencia'];
            if (!preg_match('/\A[A-Za-z0-9]{1,50}\z/D',$reference)) {
                throw new RuntimeException('El inventario contiene una referencia por revisar.');
            }
            $type = $item['tipo'];
            if (!isset($categories[$type])) {
                $findCategory->execute([$type]);
                $category = $findCategory->fetchColumn();
                if ($category === false) {
                    $insertCategory->execute([$type]);
                    $category = $db->lastInsertId();
                }
                $categories[$type] = (int)$category;
            }
            $category = $categories[$type];
            $values = [$category,$item['nombre'],$item['observaciones'] ?: null];
            $findResource->execute([$reference]);
            $existing = $findResource->fetch();
            if (!$existing) {
                $insert->execute([$reference,...$values]);
                continue;
            }
            if ((int)$existing['categoria_id'] !== $category) {
                $movement->execute([$existing['id'],$existing['id']]);
                if ($movement->fetchColumn()) {
                    throw new RuntimeException('Un recurso con operaciones vigentes cambiaría de categoría.');
                }
            }
            $update->execute([...$values,$existing['id']]);
        }
    }
}
