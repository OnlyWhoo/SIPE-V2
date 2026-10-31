
CREATE DATABASE IF NOT EXISTS sipe
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE sipe;

SET NAMES utf8mb4;

-- =========================================
-- 1. ROLES
-- =========================================

CREATE TABLE roles (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(40) NOT NULL UNIQUE,
    descripcion VARCHAR(255)
) ENGINE=InnoDB;

-- =========================================
-- 2. USUARIOS DEL SISTEMA
-- =========================================

CREATE TABLE usuarios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rol_id TINYINT UNSIGNED NOT NULL,
    nombre_completo VARCHAR(150) NOT NULL,
    correo VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_acceso DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (rol_id) REFERENCES roles(id)
        ON DELETE RESTRICT,

    CHECK (activo IN (0,1))
) ENGINE=InnoDB;

-- =========================================
-- 3. PROGRAMAS ACADEMICOS
-- =========================================

CREATE TABLE programas_academicos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CHECK (activo IN (0,1))
) ENGINE=InnoDB;

-- =========================================
-- 4. ESTUDIANTES
-- =========================================

CREATE TABLE estudiantes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo_estudiante VARCHAR(40) NOT NULL UNIQUE,
    programa_id BIGINT UNSIGNED NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    correo VARCHAR(180) UNIQUE,
    qr_token CHAR(64) NOT NULL UNIQUE,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (programa_id)
        REFERENCES programas_academicos(id)
        ON DELETE RESTRICT,

    CHECK (activo IN (0,1))
) ENGINE=InnoDB;

-- =========================================
-- 5. CATEGORIAS DE RECURSOS
-- =========================================

CREATE TABLE categorias_recursos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion TEXT,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CHECK (activo IN (0,1))
) ENGINE=InnoDB;

-- =========================================
-- 6. INVENTARIO
-- =========================================

CREATE TABLE recursos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    categoria_id BIGINT UNSIGNED NOT NULL,
    codigo_inventario VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT,

    estado_fisico ENUM(
        'BUENO',
        'REGULAR',
        'DANADO'
    ) NOT NULL DEFAULT 'BUENO',

    estado_operativo ENUM(
        'DISPONIBLE',
        'PRESTADO',
        'MANTENIMIENTO',
        'BAJA'
    ) NOT NULL DEFAULT 'DISPONIBLE',

    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (categoria_id)
        REFERENCES categorias_recursos(id)
        ON DELETE RESTRICT,

    INDEX idx_recursos_estado (estado_operativo, activo),

    CHECK (activo IN (0,1))
) ENGINE=InnoDB;

-- =========================================
-- 7. AUTORIZACIONES POR PROGRAMA
-- =========================================

CREATE TABLE autorizaciones_programa_categoria (
    programa_id BIGINT UNSIGNED NOT NULL,
    categoria_id BIGINT UNSIGNED NOT NULL,
    autorizado_por BIGINT UNSIGNED NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (programa_id, categoria_id),

    FOREIGN KEY (programa_id)
        REFERENCES programas_academicos(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (categoria_id)
        REFERENCES categorias_recursos(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (autorizado_por)
        REFERENCES usuarios(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================================
-- 8. RESERVAS
-- =========================================

CREATE TABLE reservas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estudiante_id BIGINT UNSIGNED NOT NULL,
    recurso_id BIGINT UNSIGNED NOT NULL,
    creada_por BIGINT UNSIGNED NOT NULL,

    inicio DATETIME NOT NULL,
    fin DATETIME NOT NULL,

    estado ENUM(
        'CONFIRMADA',
        'CANCELADA',
        'ATENDIDA',
        'VENCIDA'
    ) NOT NULL DEFAULT 'CONFIRMADA',

    observaciones TEXT,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (estudiante_id)
        REFERENCES estudiantes(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (recurso_id)
        REFERENCES recursos(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (creada_por)
        REFERENCES usuarios(id)
        ON DELETE RESTRICT,

    INDEX idx_reservas_solapamiento (
        recurso_id, estado, inicio, fin
    ),

    CHECK (fin > inicio)
) ENGINE=InnoDB;

-- =========================================
-- 9. PRESTAMOS Y ENTREGAS
-- =========================================

CREATE TABLE prestamos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estudiante_id BIGINT UNSIGNED NOT NULL,
    recurso_id BIGINT UNSIGNED NOT NULL,
    reserva_id BIGINT UNSIGNED NULL,
    entregado_por BIGINT UNSIGNED NOT NULL,

    fecha_entrega DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_limite DATETIME NOT NULL,

    estado ENUM(
        'ACTIVO',
        'DEVUELTO',
        'ANULADO'
    ) NOT NULL DEFAULT 'ACTIVO',

    observaciones_entrega TEXT,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_prestamos_reserva (reserva_id),

    FOREIGN KEY (estudiante_id)
        REFERENCES estudiantes(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (recurso_id)
        REFERENCES recursos(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (reserva_id)
        REFERENCES reservas(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (entregado_por)
        REFERENCES usuarios(id)
        ON DELETE RESTRICT,

    INDEX idx_prestamos_recurso_estado (
        recurso_id, estado
    ),

    INDEX idx_prestamos_estudiante (
        estudiante_id, estado
    ),

    CHECK (fecha_limite > fecha_entrega)
) ENGINE=InnoDB;

-- =========================================
-- 10. DEVOLUCIONES
-- =========================================

CREATE TABLE devoluciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prestamo_id BIGINT UNSIGNED NOT NULL UNIQUE,
    recibido_por BIGINT UNSIGNED NOT NULL,

    fecha_devolucion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    estado_retorno ENUM(
        'BUENO',
        'REGULAR',
        'DANADO'
    ) NOT NULL,

    observaciones TEXT,

    FOREIGN KEY (prestamo_id)
        REFERENCES prestamos(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (recibido_por)
        REFERENCES usuarios(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =========================================
-- 11. INSPECCIONES DE RECURSOS
-- =========================================

CREATE TABLE inspecciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recurso_id BIGINT UNSIGNED NOT NULL,
    prestamo_id BIGINT UNSIGNED NULL,
    inspeccionado_por BIGINT UNSIGNED NOT NULL,

    momento ENUM(
        'ANTES_ENTREGA',
        'EN_DEVOLUCION',
        'PERIODICA'
    ) NOT NULL,

    estado_fisico ENUM(
        'BUENO',
        'REGULAR',
        'DANADO'
    ) NOT NULL,

    apto_para_prestamo TINYINT(1) NOT NULL,
    observaciones TEXT,
    fecha_inspeccion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (recurso_id)
        REFERENCES recursos(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (prestamo_id)
        REFERENCES prestamos(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (inspeccionado_por)
        REFERENCES usuarios(id)
        ON DELETE RESTRICT,

    CHECK (apto_para_prestamo IN (0,1))
) ENGINE=InnoDB;

-- =========================================
-- 12. SANCIONES
-- =========================================

CREATE TABLE sanciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estudiante_id BIGINT UNSIGNED NOT NULL,
    prestamo_id BIGINT UNSIGNED NULL,
    registrada_por BIGINT UNSIGNED NOT NULL,

    tipo ENUM(
        'MORA',
        'DANIO',
        'OTRA'
    ) NOT NULL,

    motivo TEXT NOT NULL,
    monto DECIMAL(12,2) NULL,

    estado ENUM(
        'ABIERTA',
        'RESUELTA',
        'ANULADA'
    ) NOT NULL DEFAULT 'ABIERTA',

    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_resolucion DATETIME NULL,
    observaciones_resolucion TEXT,

    FOREIGN KEY (estudiante_id)
        REFERENCES estudiantes(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (prestamo_id)
        REFERENCES prestamos(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (registrada_por)
        REFERENCES usuarios(id)
        ON DELETE RESTRICT,

    CHECK (monto IS NULL OR monto >= 0)
) ENGINE=InnoDB;

-- =========================================
-- 13. AUDITORIA
-- =========================================

CREATE TABLE auditoria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NULL,
    accion VARCHAR(100) NOT NULL,
    entidad VARCHAR(80) NOT NULL,
    entidad_id BIGINT UNSIGNED NULL,
    detalle TEXT,
    datos_antes LONGTEXT,
    datos_despues LONGTEXT,
    ip_origen VARCHAR(45),
    fecha_evento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id)
        ON DELETE SET NULL,

    INDEX idx_auditoria_fecha (fecha_evento),
    INDEX idx_auditoria_entidad (entidad, entidad_id)
) ENGINE=InnoDB;

-- =========================================
-- 14. CONFIGURACIONES DEL SISTEMA
-- =========================================

CREATE TABLE configuraciones (
    clave VARCHAR(100) PRIMARY KEY,
    valor VARCHAR(255) NOT NULL,
    descripcion TEXT,
    actualizado_por BIGINT UNSIGNED NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (actualizado_por)
        REFERENCES usuarios(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================================
-- ROLES INICIALES
-- =========================================

INSERT IGNORE INTO roles (id, nombre, descripcion)
VALUES
(
    1,
    'ADMINISTRADOR',
    'Gestion institucional, inventario, sanciones y auditoria'
),
(
    2,
    'EMPLEADO',
    'Consulta de estudiantes, reservas, prestamos y devoluciones'
);

-- =========================================
-- VISTA DE CONSULTA DE PRESTAMOS
-- =========================================

CREATE OR REPLACE VIEW vw_prestamos_resumen AS

SELECT
    p.id AS prestamo_id,
    e.codigo_estudiante,
    CONCAT(e.nombres, ' ', e.apellidos) AS estudiante,
    r.codigo_inventario,
    r.nombre AS recurso,
    p.fecha_entrega,
    p.fecha_limite,
    d.fecha_devolucion,

    CASE
        WHEN p.estado = 'ANULADO'
            THEN 'ANULADO'
        WHEN d.id IS NOT NULL
            THEN 'DEVUELTO'
        WHEN NOW() > p.fecha_limite
            THEN 'ATRASADO'
        ELSE 'ACTIVO'
    END AS situacion

FROM prestamos p

INNER JOIN estudiantes e
    ON e.id = p.estudiante_id

INNER JOIN recursos r
    ON r.id = p.recurso_id

LEFT JOIN devoluciones d
    ON d.prestamo_id = p.id;

-- =========================================
-- VERIFICAR TABLAS CREADAS
-- =========================================

SHOW FULL TABLES;
