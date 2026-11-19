ALTER TABLE estudiantes
    MODIFY COLUMN tipo_persona ENUM('ESTUDIANTE','EGRESADO','ADMINISTRATIVO','DOCENTE') NOT NULL DEFAULT 'ESTUDIANTE',
    ADD COLUMN cedula VARCHAR(40) NULL,
    ADD COLUMN carnet_vigencia DATE NULL,
    ADD COLUMN carnet_origen VARCHAR(32) NULL,
    ADD COLUMN clasificacion_pendiente TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN programa_provisional TINYINT(1) NOT NULL DEFAULT 0,
    ADD UNIQUE KEY uq_estudiantes_cedula (cedula);

CREATE TABLE fotografias_prestatarios (
    estudiante_id BIGINT UNSIGNED PRIMARY KEY,
    mime VARCHAR(30) NOT NULL,
    contenido MEDIUMBLOB NOT NULL,
    origen ENUM('ILUSTRATIVA','ADMINISTRATIVA') NOT NULL,
    CONSTRAINT fk_foto_prestatario FOREIGN KEY (estudiante_id)
        REFERENCES estudiantes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE recursos MODIFY COLUMN nombre VARCHAR(255) NOT NULL;
