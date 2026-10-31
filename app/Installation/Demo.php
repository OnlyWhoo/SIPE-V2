<?php
declare(strict_types=1);
namespace Sipe\Installation;
use PDO;

final class Demo
{
    public static function seed(PDO $db): void {
        $db->exec("INSERT INTO roles(id,nombre,descripcion) VALUES(1,'ADMINISTRADOR','Gestión institucional'),(2,'EMPLEADO','Operación de préstamos');
        INSERT INTO programas_academicos(id,codigo,nombre) VALUES(1,'DEMO-DIS','Diseño DEMO'),(2,'DEMO-MUL','Multimedia DEMO');
        INSERT INTO categorias_recursos(id,nombre) VALUES(1,'Cámaras DEMO'),(2,'Accesorios DEMO');
        INSERT INTO estudiantes(id,codigo_estudiante,programa_id,nombres,apellidos,correo,qr_token,tipo_persona) VALUES
        (1,'DEMO-001',1,'Persona DEMO','Uno','demo1@example.invalid',SHA2('SIPE-V2-DEMO-001',256),'ESTUDIANTE'),
        (2,'DEMO-002',2,'Persona DEMO','Dos','demo2@example.invalid',SHA2('SIPE-V2-DEMO-002',256),'ESTUDIANTE'),
        (3,'DEMO-EGR',NULL,'Egresado DEMO','Tres','demo3@example.invalid',SHA2('SIPE-V2-DEMO-EGR',256),'EGRESADO'),
        (4,'DEMO-ADM',NULL,'Administrativo DEMO','Cuatro','demo4@example.invalid',SHA2('SIPE-V2-DEMO-ADM',256),'ADMINISTRATIVO');
        INSERT INTO recursos(id,categoria_id,codigo_inventario,nombre) VALUES
        (1,1,'DEMO-CAM-01','Cámara prestada DEMO'),(2,2,'DEMO-TAB-01','Tableta disponible DEMO'),(3,2,'DEMO-TRI-01','Trípode reservado DEMO'),(4,1,'DEMO-CAM-02','Cámara disponible DEMO'),(5,2,'DEMO-MIC-01','Micrófono disponible DEMO');");
        $db->prepare('INSERT INTO usuarios(id,rol_id,nombre_completo,correo,password_hash) VALUES(1,1,?,?,?)')->execute(['Profesor DEMO',Installer::DEMO_EMAIL,password_hash(Installer::DEMO_PASSWORD,PASSWORD_DEFAULT)]);
        $db->exec("INSERT INTO autorizaciones_programa_categoria(programa_id,categoria_id,autorizado_por) VALUES(1,1,1),(1,2,1),(2,1,1),(2,2,1);
        INSERT INTO configuraciones(clave,valor,descripcion) VALUES('hora_limite_interno','20:00','Devolución interna máxima America/Bogota');
        INSERT INTO reservas(id,estudiante_id,recurso_id,creada_por,inicio,fin,tipo_prestamo,grupo_trabajo,observaciones) VALUES(1,1,3,1,DATE_ADD(NOW(),INTERVAL 1 DAY),DATE_ADD(NOW(),INTERVAL 25 HOUR),'EXTERNO','ESTUDIANTE','DEMO: reserva futura ficticia');
        INSERT INTO prestamos(id,estudiante_id,recurso_id,entregado_por,fecha_entrega,fecha_limite,estado,tipo_prestamo,grupo_trabajo,observaciones_entrega) VALUES
        (1,1,1,1,NOW(),DATE_ADD(NOW(),INTERVAL 2 DAY),'ACTIVO','EXTERNO','ESTUDIANTE','DEMO: préstamo activo ficticio'),
        (2,2,2,1,DATE_SUB(NOW(),INTERVAL 4 DAY),DATE_SUB(NOW(),INTERVAL 3 DAY),'DEVUELTO','EXTERNO','ESTUDIANTE','DEMO: préstamo finalizado ficticio');
        INSERT INTO devoluciones(prestamo_id,recibido_por,fecha_devolucion,estado_retorno,observaciones) VALUES(2,1,DATE_SUB(NOW(),INTERVAL 1 DAY),'BUENO','DEMO: devolución ficticia');
        INSERT INTO inspecciones(recurso_id,prestamo_id,inspeccionado_por,momento,estado_fisico,apto_para_prestamo,observaciones) VALUES(1,1,1,'ANTES_ENTREGA','BUENO',1,'DEMO'),(2,2,1,'ANTES_ENTREGA','BUENO',1,'DEMO'),(2,2,1,'EN_DEVOLUCION','BUENO',1,'DEMO');
        UPDATE recursos SET estado_operativo='PRESTADO' WHERE id=1;
        INSERT INTO sanciones(estudiante_id,prestamo_id,registrada_por,tipo,motivo,estado) VALUES(2,2,1,'MORA','DEMO: decisión ficticia según reglamento de demostración, sin cobro real','RESUELTA');
        INSERT INTO auditoria(usuario_id,accion,entidad,entidad_id,detalle) VALUES(1,'INSTALACION_DEMO','usuarios',1,'SIPE V2: datos enteramente ficticios; cuenta exclusiva de localhost');");
    }
}
