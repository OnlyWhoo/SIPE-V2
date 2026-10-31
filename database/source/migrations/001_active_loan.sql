-- Migración aditiva MySQL 8. No altera ni elimina movimientos existentes.
-- Antes de aplicar: SELECT recurso_id,COUNT(*) FROM prestamos
-- WHERE estado='ACTIVO' GROUP BY recurso_id HAVING COUNT(*)>1;
-- Si hay duplicados, detener y resolverlos con autorización.
ALTER TABLE prestamos
 ADD COLUMN recurso_activo_id BIGINT UNSIGNED
 GENERATED ALWAYS AS (CASE WHEN estado='ACTIVO' THEN recurso_id ELSE NULL END) STORED,
 ADD UNIQUE KEY uq_recurso_prestamo_activo (recurso_activo_id);
CREATE INDEX idx_auditoria_acceso ON auditoria (accion, ip_origen, fecha_evento);
