-- Aditiva. Los movimientos previos conservan NULL: no se inventa su tipo.
ALTER TABLE reservas ADD COLUMN tipo_prestamo ENUM('INTERNO','EXTERNO') NULL,
    ADD COLUMN grupo_trabajo VARCHAR(100) NULL,
    ADD COLUMN aprobado_por BIGINT UNSIGNED NULL,
    ADD COLUMN motivo_aprobacion TEXT NULL,
    ADD CONSTRAINT fk_reserva_aprobador FOREIGN KEY(aprobado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
    MODIFY COLUMN estado ENUM('CONFIRMADA','CANCELADA','ATENDIDA','VENCIDA','PENDIENTE_APROBACION') NOT NULL DEFAULT 'CONFIRMADA';
ALTER TABLE prestamos ADD COLUMN tipo_prestamo ENUM('INTERNO','EXTERNO') NULL,
    ADD COLUMN grupo_trabajo VARCHAR(100) NULL;
ALTER TABLE estudiantes ADD COLUMN tipo_persona ENUM('ESTUDIANTE','EGRESADO','ADMINISTRATIVO') NOT NULL DEFAULT 'ESTUDIANTE',
    MODIFY COLUMN programa_id BIGINT UNSIGNED NULL;
INSERT IGNORE INTO configuraciones(clave,valor,descripcion) VALUES
('hora_limite_interno','20:00','Hora máxima de devolución interna, America/Bogota');
