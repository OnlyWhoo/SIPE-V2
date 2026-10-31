-- SIPE V2: esquema consolidado, migraciones 001 y 002 integradas.
-- Referencia técnica. La instalación web usa install-plan.json, sin importación manual.
CREATE TABLE `roles` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(40) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `usuarios` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rol_id` tinyint(3) unsigned NOT NULL,
  `nombre_completo` varchar(150) NOT NULL,
  `correo` varchar(180) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_acceso` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `correo` (`correo`),
  KEY `rol_id` (`rol_id`),
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `usuarios_check_1` CHECK (`activo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `programas_academicos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  CONSTRAINT `programas_academicos_check_1` CHECK (`activo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `estudiantes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `codigo_estudiante` varchar(40) NOT NULL,
  `programa_id` bigint(20) unsigned DEFAULT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `correo` varchar(180) DEFAULT NULL,
  `qr_token` char(64) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tipo_persona` enum('ESTUDIANTE','EGRESADO','ADMINISTRATIVO') NOT NULL DEFAULT 'ESTUDIANTE',
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo_estudiante` (`codigo_estudiante`),
  UNIQUE KEY `qr_token` (`qr_token`),
  UNIQUE KEY `correo` (`correo`),
  KEY `programa_id` (`programa_id`),
  CONSTRAINT `estudiantes_ibfk_1` FOREIGN KEY (`programa_id`) REFERENCES `programas_academicos` (`id`),
  CONSTRAINT `estudiantes_check_1` CHECK (`activo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `categorias_recursos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`),
  CONSTRAINT `categorias_recursos_check_1` CHECK (`activo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `recursos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `categoria_id` bigint(20) unsigned NOT NULL,
  `codigo_inventario` varchar(50) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `estado_fisico` enum('BUENO','REGULAR','DANADO') NOT NULL DEFAULT 'BUENO',
  `estado_operativo` enum('DISPONIBLE','PRESTADO','MANTENIMIENTO','BAJA') NOT NULL DEFAULT 'DISPONIBLE',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo_inventario` (`codigo_inventario`),
  KEY `categoria_id` (`categoria_id`),
  KEY `idx_recursos_estado` (`estado_operativo`,`activo`),
  CONSTRAINT `recursos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_recursos` (`id`),
  CONSTRAINT `recursos_check_1` CHECK (`activo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `autorizaciones_programa_categoria` (
  `programa_id` bigint(20) unsigned NOT NULL,
  `categoria_id` bigint(20) unsigned NOT NULL,
  `autorizado_por` bigint(20) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`programa_id`,`categoria_id`),
  KEY `categoria_id` (`categoria_id`),
  KEY `autorizado_por` (`autorizado_por`),
  CONSTRAINT `autorizaciones_programa_categoria_ibfk_1` FOREIGN KEY (`programa_id`) REFERENCES `programas_academicos` (`id`),
  CONSTRAINT `autorizaciones_programa_categoria_ibfk_2` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_recursos` (`id`),
  CONSTRAINT `autorizaciones_programa_categoria_ibfk_3` FOREIGN KEY (`autorizado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reservas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `estudiante_id` bigint(20) unsigned NOT NULL,
  `recurso_id` bigint(20) unsigned NOT NULL,
  `creada_por` bigint(20) unsigned NOT NULL,
  `inicio` datetime NOT NULL,
  `fin` datetime NOT NULL,
  `estado` enum('CONFIRMADA','CANCELADA','ATENDIDA','VENCIDA','PENDIENTE_APROBACION') NOT NULL DEFAULT 'CONFIRMADA',
  `observaciones` text DEFAULT NULL,
  `creada_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizada_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tipo_prestamo` enum('INTERNO','EXTERNO') DEFAULT NULL,
  `grupo_trabajo` varchar(100) DEFAULT NULL,
  `aprobado_por` bigint(20) unsigned DEFAULT NULL,
  `motivo_aprobacion` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `estudiante_id` (`estudiante_id`),
  KEY `creada_por` (`creada_por`),
  KEY `idx_reservas_solapamiento` (`recurso_id`,`estado`,`inicio`,`fin`),
  KEY `fk_reserva_aprobador` (`aprobado_por`),
  CONSTRAINT `fk_reserva_aprobador` FOREIGN KEY (`aprobado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `reservas_ibfk_1` FOREIGN KEY (`estudiante_id`) REFERENCES `estudiantes` (`id`),
  CONSTRAINT `reservas_ibfk_2` FOREIGN KEY (`recurso_id`) REFERENCES `recursos` (`id`),
  CONSTRAINT `reservas_ibfk_3` FOREIGN KEY (`creada_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `reservas_check_1` CHECK (`fin` > `inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `prestamos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `estudiante_id` bigint(20) unsigned NOT NULL,
  `recurso_id` bigint(20) unsigned NOT NULL,
  `reserva_id` bigint(20) unsigned DEFAULT NULL,
  `entregado_por` bigint(20) unsigned NOT NULL,
  `fecha_entrega` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_limite` datetime NOT NULL,
  `estado` enum('ACTIVO','DEVUELTO','ANULADO') NOT NULL DEFAULT 'ACTIVO',
  `observaciones_entrega` text DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `recurso_activo_id` bigint(20) unsigned GENERATED ALWAYS AS (case when `estado` = 'ACTIVO' then `recurso_id` else NULL end) STORED,
  `tipo_prestamo` enum('INTERNO','EXTERNO') DEFAULT NULL,
  `grupo_trabajo` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prestamos_reserva` (`reserva_id`),
  UNIQUE KEY `uq_recurso_prestamo_activo` (`recurso_activo_id`),
  KEY `entregado_por` (`entregado_por`),
  KEY `idx_prestamos_recurso_estado` (`recurso_id`,`estado`),
  KEY `idx_prestamos_estudiante` (`estudiante_id`,`estado`),
  CONSTRAINT `prestamos_ibfk_1` FOREIGN KEY (`estudiante_id`) REFERENCES `estudiantes` (`id`),
  CONSTRAINT `prestamos_ibfk_2` FOREIGN KEY (`recurso_id`) REFERENCES `recursos` (`id`),
  CONSTRAINT `prestamos_ibfk_3` FOREIGN KEY (`reserva_id`) REFERENCES `reservas` (`id`),
  CONSTRAINT `prestamos_ibfk_4` FOREIGN KEY (`entregado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `prestamos_check_1` CHECK (`fecha_limite` > `fecha_entrega`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `devoluciones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `prestamo_id` bigint(20) unsigned NOT NULL,
  `recibido_por` bigint(20) unsigned NOT NULL,
  `fecha_devolucion` datetime NOT NULL DEFAULT current_timestamp(),
  `estado_retorno` enum('BUENO','REGULAR','DANADO') NOT NULL,
  `observaciones` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prestamo_id` (`prestamo_id`),
  KEY `recibido_por` (`recibido_por`),
  CONSTRAINT `devoluciones_ibfk_1` FOREIGN KEY (`prestamo_id`) REFERENCES `prestamos` (`id`),
  CONSTRAINT `devoluciones_ibfk_2` FOREIGN KEY (`recibido_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inspecciones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recurso_id` bigint(20) unsigned NOT NULL,
  `prestamo_id` bigint(20) unsigned DEFAULT NULL,
  `inspeccionado_por` bigint(20) unsigned NOT NULL,
  `momento` enum('ANTES_ENTREGA','EN_DEVOLUCION','PERIODICA') NOT NULL,
  `estado_fisico` enum('BUENO','REGULAR','DANADO') NOT NULL,
  `apto_para_prestamo` tinyint(1) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `fecha_inspeccion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `recurso_id` (`recurso_id`),
  KEY `prestamo_id` (`prestamo_id`),
  KEY `inspeccionado_por` (`inspeccionado_por`),
  CONSTRAINT `inspecciones_ibfk_1` FOREIGN KEY (`recurso_id`) REFERENCES `recursos` (`id`),
  CONSTRAINT `inspecciones_ibfk_2` FOREIGN KEY (`prestamo_id`) REFERENCES `prestamos` (`id`),
  CONSTRAINT `inspecciones_ibfk_3` FOREIGN KEY (`inspeccionado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `inspecciones_check_1` CHECK (`apto_para_prestamo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sanciones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `estudiante_id` bigint(20) unsigned NOT NULL,
  `prestamo_id` bigint(20) unsigned DEFAULT NULL,
  `registrada_por` bigint(20) unsigned NOT NULL,
  `tipo` enum('MORA','DANIO','OTRA') NOT NULL,
  `motivo` text NOT NULL,
  `monto` decimal(12,2) DEFAULT NULL,
  `estado` enum('ABIERTA','RESUELTA','ANULADA') NOT NULL DEFAULT 'ABIERTA',
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_resolucion` datetime DEFAULT NULL,
  `observaciones_resolucion` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `estudiante_id` (`estudiante_id`),
  KEY `prestamo_id` (`prestamo_id`),
  KEY `registrada_por` (`registrada_por`),
  CONSTRAINT `sanciones_ibfk_1` FOREIGN KEY (`estudiante_id`) REFERENCES `estudiantes` (`id`),
  CONSTRAINT `sanciones_ibfk_2` FOREIGN KEY (`prestamo_id`) REFERENCES `prestamos` (`id`),
  CONSTRAINT `sanciones_ibfk_3` FOREIGN KEY (`registrada_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `sanciones_check_1` CHECK (`monto` is null or `monto` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auditoria` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned DEFAULT NULL,
  `accion` varchar(100) NOT NULL,
  `entidad` varchar(80) NOT NULL,
  `entidad_id` bigint(20) unsigned DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `datos_antes` longtext DEFAULT NULL,
  `datos_despues` longtext DEFAULT NULL,
  `ip_origen` varchar(45) DEFAULT NULL,
  `fecha_evento` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_auditoria_fecha` (`fecha_evento`),
  KEY `idx_auditoria_entidad` (`entidad`,`entidad_id`),
  KEY `idx_auditoria_acceso` (`accion`,`ip_origen`,`fecha_evento`),
  CONSTRAINT `auditoria_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `configuraciones` (
  `clave` varchar(100) NOT NULL,
  `valor` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `actualizado_por` bigint(20) unsigned DEFAULT NULL,
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`clave`),
  KEY `actualizado_por` (`actualizado_por`),
  CONSTRAINT `configuraciones_ibfk_1` FOREIGN KEY (`actualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE SQL SECURITY INVOKER VIEW vw_prestamos_resumen AS select `p`.`id` AS `prestamo_id`,`e`.`codigo_estudiante` AS `codigo_estudiante`,concat(`e`.`nombres`,' ',`e`.`apellidos`) AS `estudiante`,`r`.`codigo_inventario` AS `codigo_inventario`,`r`.`nombre` AS `recurso`,`p`.`fecha_entrega` AS `fecha_entrega`,`p`.`fecha_limite` AS `fecha_limite`,`d`.`fecha_devolucion` AS `fecha_devolucion`,case when `p`.`estado` = 'ANULADO' then 'ANULADO' when `d`.`id` is not null then 'DEVUELTO' when current_timestamp() > `p`.`fecha_limite` then 'ATRASADO' else 'ACTIVO' end AS `situacion` from (((`prestamos` `p` join `estudiantes` `e` on(`e`.`id` = `p`.`estudiante_id`)) join `recursos` `r` on(`r`.`id` = `p`.`recurso_id`)) left join `devoluciones` `d` on(`d`.`prestamo_id` = `p`.`id`));
