-- Servicios Institucionales - esquema objetivo
-- Motor requerido: MySQL 8.0.16 o superior (CHECK constraints aplicados)
-- Codificacion: utf8mb4 / UTC para instantes
-- Este script crea una base nueva. No modifica las bases legacy.

-- Falla intencionalmente si la base ya existe. Esto evita mezclar la linea base
-- con un esquema parcial o desplegado previamente.
CREATE DATABASE `servicios_moderno`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

USE `servicios_moderno`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- Infraestructura compartida
-- -----------------------------------------------------------------------------

CREATE TABLE `compartido_archivos_almacenados` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `disco` VARCHAR(40) NOT NULL,
  `clave_objeto` VARCHAR(500) NOT NULL,
  `nombre_original` VARCHAR(255) NOT NULL,
  `tipo_mime` VARCHAR(127) NOT NULL,
  `tamano_bytes` BIGINT UNSIGNED NOT NULL,
  `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `estado_escaneo` VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
  `id_identidad_carga` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Referencia logica a IAM',
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `eliminado_en` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_compartido_archivo_objeto` (`disco`, `clave_objeto`),
  KEY `idx_compartido_archivo_hash` (`sha256`),
  KEY `idx_compartido_archivo_cargador` (`id_identidad_carga`, `creado_en`),
  CONSTRAINT `chk_compartido_archivo_tamano` CHECK (`tamano_bytes` > 0),
  CONSTRAINT `chk_compartido_archivo_escaneo` CHECK (`estado_escaneo` IN ('PENDIENTE','LIMPIO','INFECTADO','FALLIDO'))
) ENGINE=InnoDB;

CREATE TABLE `compartido_mensajes_salida` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre_contexto` VARCHAR(60) NOT NULL,
  `tipo_agregado` VARCHAR(100) NOT NULL,
  `id_agregado` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tipo_evento` VARCHAR(160) NOT NULL,
  `contenido` JSON NOT NULL,
  `ocurrido_en` DATETIME(6) NOT NULL,
  `publicado_en` DATETIME(6) NULL,
  `intentos` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `ultimo_error` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_salida_pendiente` (`publicado_en`, `ocurrido_en`),
  KEY `idx_salida_agregado` (`nombre_contexto`, `tipo_agregado`, `id_agregado`)
) ENGINE=InnoDB;

CREATE TABLE `compartido_claves_idempotencia` (
  `clave_idempotencia` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `alcance` VARCHAR(100) NOT NULL,
  `hash_solicitud` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `estado_respuesta` SMALLINT UNSIGNED NULL,
  `cuerpo_respuesta` JSON NULL,
  `bloqueado_hasta` DATETIME(6) NULL,
  `expira_en` DATETIME(6) NOT NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`alcance`, `clave_idempotencia`),
  KEY `idx_idempotencia_expiracion` (`expira_en`)
) ENGINE=InnoDB;

CREATE TABLE `compartido_registros_auditoria` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ocurrido_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `id_identidad_actor` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Referencia logica a IAM',
  `nombre_contexto` VARCHAR(60) NOT NULL,
  `accion` VARCHAR(120) NOT NULL,
  `tipo_sujeto` VARCHAR(100) NOT NULL,
  `id_sujeto` VARCHAR(100) NOT NULL,
  `id_correlacion` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `direccion_ip` VARBINARY(16) NULL,
  `metadatos` JSON NULL,
  PRIMARY KEY (`id`),
  KEY `idx_auditoria_asignatura` (`nombre_contexto`, `tipo_sujeto`, `id_sujeto`, `ocurrido_en`),
  KEY `idx_auditoria_actor` (`id_identidad_actor`, `ocurrido_en`)
) ENGINE=InnoDB;

CREATE TABLE `compartido_politicas_retencion` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(160) NOT NULL,
  `meses_archivo_circular` SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `meses_archivo_historico` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `meses_para_depurar` SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  `depuracion_automatica` BOOLEAN NOT NULL DEFAULT FALSE,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_compartido_retencion_codigo` (`codigo`),
  CONSTRAINT `chk_compartido_retencion_plazos` CHECK (
    `meses_archivo_circular` > 0
    AND `meses_archivo_historico` >= `meses_archivo_circular`
    AND `meses_para_depurar` >= `meses_archivo_historico`
  )
) ENGINE=InnoDB;

CREATE TABLE `compartido_registros_retencion` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_politica_retencion` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre_contexto` VARCHAR(60) NOT NULL,
  `tipo_sujeto` VARCHAR(100) NOT NULL,
  `id_sujeto` VARCHAR(100) NOT NULL,
  `fecha_referencia` DATE NOT NULL,
  `fecha_archivo_circular` DATE NOT NULL,
  `fecha_archivo_historico` DATE NOT NULL,
  `fecha_depurable` DATE NOT NULL,
  `estado_retencion` VARCHAR(30) NOT NULL DEFAULT 'ACTIVO',
  `bloqueo_legal` BOOLEAN NOT NULL DEFAULT FALSE,
  `motivo_bloqueo` VARCHAR(500) NULL,
  `evaluado_en` DATETIME(6) NULL,
  `depurado_en` DATETIME(6) NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_compartido_retencion_sujeto` (`nombre_contexto`, `tipo_sujeto`, `id_sujeto`),
  KEY `idx_compartido_retencion_estado_fecha` (`estado_retencion`, `fecha_depurable`),
  CONSTRAINT `fk_compartido_retencion_politica` FOREIGN KEY (`id_politica_retencion`) REFERENCES `compartido_politicas_retencion` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_compartido_retencion_fechas` CHECK (
    `fecha_archivo_historico` >= `fecha_archivo_circular`
    AND `fecha_depurable` >= `fecha_archivo_historico`
  ),
  CONSTRAINT `chk_compartido_retencion_estado` CHECK (`estado_retencion` IN ('ACTIVO','CIRCULAR','HISTORICO','DEPURABLE','DEPURADO')),
  CONSTRAINT `chk_compartido_retencion_bloqueo` CHECK (`bloqueo_legal` = FALSE OR `motivo_bloqueo` IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE `migracion_mapa_ids` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `base_origen` VARCHAR(64) NOT NULL,
  `tabla_origen` VARCHAR(64) NOT NULL,
  `id_legacy` VARCHAR(100) NOT NULL,
  `contexto_destino` VARCHAR(60) NOT NULL,
  `tabla_destino` VARCHAR(64) NOT NULL,
  `id_destino` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `hash_origen` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `migrado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_legacy_origen` (`base_origen`, `tabla_origen`, `id_legacy`),
  UNIQUE KEY `uq_legacy_destino` (`tabla_destino`, `id_destino`)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Contexto delimitado IAM
-- -----------------------------------------------------------------------------

CREATE TABLE `iam_identidades` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre_mostrado` VARCHAR(255) NOT NULL,
  `correo_normalizado` VARCHAR(254) NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  `ultimo_acceso_en` DATETIME(6) NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `deshabilitado_en` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_iam_identidad_correo` (`correo_normalizado`),
  CONSTRAINT `chk_iam_identidad_correo` CHECK (`correo_normalizado` IS NULL OR `correo_normalizado` = LOWER(TRIM(`correo_normalizado`))),
  CONSTRAINT `chk_iam_identidad_estado` CHECK (`estado` IN ('PENDIENTE','ACTIVO','DESHABILITADO','ARCHIVADO'))
) ENGINE=InnoDB;

CREATE TABLE `iam_cuentas_externas` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_identidad` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `proveedor` VARCHAR(40) NOT NULL,
  `id_inquilino` VARCHAR(80) NOT NULL,
  `sujeto_proveedor` VARCHAR(128) NOT NULL,
  `nombre_principal` VARCHAR(254) NULL,
  `instantanea_atributos` JSON NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_iam_externo_asignatura` (`proveedor`, `id_inquilino`, `sujeto_proveedor`),
  KEY `idx_iam_externo_identidad` (`id_identidad`),
  CONSTRAINT `fk_iam_externo_identidad` FOREIGN KEY (`id_identidad`) REFERENCES `iam_identidades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `iam_roles` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(160) NOT NULL,
  `descripcion` TEXT NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_iam_rol_codigo` (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE `iam_permisos` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `descripcion` TEXT NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_iam_permiso_codigo` (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE `iam_identidad_roles` (
  `id_identidad` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_rol` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_identidad_otorgante` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `otorgado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `expira_en` DATETIME(6) NULL,
  PRIMARY KEY (`id_identidad`, `id_rol`),
  KEY `idx_iam_identidad_rol_rol` (`id_rol`),
  CONSTRAINT `fk_iam_identidad_rol_identidad` FOREIGN KEY (`id_identidad`) REFERENCES `iam_identidades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iam_identidad_rol_rol` FOREIGN KEY (`id_rol`) REFERENCES `iam_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iam_identidad_rol_otorgante` FOREIGN KEY (`id_identidad_otorgante`) REFERENCES `iam_identidades` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `iam_rol_permisos` (
  `id_rol` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_permiso` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `otorgado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id_rol`, `id_permiso`),
  KEY `idx_iam_rol_permiso_permiso` (`id_permiso`),
  CONSTRAINT `fk_iam_rol_permiso_rol` FOREIGN KEY (`id_rol`) REFERENCES `iam_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iam_rol_permiso_permiso` FOREIGN KEY (`id_permiso`) REFERENCES `iam_permisos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Contexto delimitado Organizacion
-- -----------------------------------------------------------------------------

CREATE TABLE `organizacion_empleados` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_identidad` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Referencia logica a IAM',
  `numero_empleado` VARCHAR(40) NULL,
  `nombres` VARCHAR(160) NOT NULL,
  `primer_apellido` VARCHAR(120) NOT NULL,
  `segundo_apellido` VARCHAR(120) NULL,
  `correo_institucional` VARCHAR(254) NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_empleado_identidad` (`id_identidad`),
  UNIQUE KEY `uq_org_empleado_number` (`numero_empleado`),
  UNIQUE KEY `uq_org_empleado_correo` (`correo_institucional`),
  CONSTRAINT `chk_org_empleado_estado` CHECK (`estado` IN ('ACTIVO','EN_LICENCIA','INACTIVO','ARCHIVADO'))
) ENGINE=InnoDB;

CREATE TABLE `organizacion_unidades` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_padre` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `codigo` VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(180) NOT NULL,
  `tipo_unidad` VARCHAR(40) NOT NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_unidad_codigo` (`codigo`),
  KEY `idx_org_unidad_padre` (`id_padre`),
  CONSTRAINT `fk_org_unidad_padre` FOREIGN KEY (`id_padre`) REFERENCES `organizacion_unidades` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_org_unidad_not_self` CHECK (`id_padre` IS NULL OR `id_padre` <> `id`)
) ENGINE=InnoDB;

CREATE TABLE `organizacion_asignaciones_puesto` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_empleado` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_unidad_organizacional` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo_puesto` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `nombre_puesto` VARCHAR(180) NOT NULL,
  `origen_dato` VARCHAR(30) NOT NULL DEFAULT 'MICROSOFT_JOBTITLE',
  `cadena_origen` VARCHAR(1000) NULL COMMENT 'Cadena JobTitle recibida al sincronizar el perfil',
  `orden_en_origen` SMALLINT UNSIGNED NULL COMMENT 'Posicion dentro de Puesto 1 / Puesto 2 / ...',
  `fecha_inicio` DATE NOT NULL,
  `fecha_fin` DATE NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_org_puesto_empleado_active` (`id_empleado`, `fecha_fin`),
  KEY `idx_org_puesto_unidad_active` (`id_unidad_organizacional`, `fecha_fin`),
  CONSTRAINT `fk_org_puesto_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `organizacion_empleados` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_org_puesto_unidad` FOREIGN KEY (`id_unidad_organizacional`) REFERENCES `organizacion_unidades` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_org_puesto_origen` CHECK (`origen_dato` IN ('MICROSOFT_JOBTITLE','MANUAL','MIGRACION')),
  CONSTRAINT `chk_org_puesto_orden` CHECK (`orden_en_origen` IS NULL OR `orden_en_origen` > 0),
  CONSTRAINT `chk_org_puesto_fechas` CHECK (`fecha_fin` IS NULL OR `fecha_fin` >= `fecha_inicio`)
) ENGINE=InnoDB;

CREATE TABLE `organizacion_niveles_academicos` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `orden_jerarquia` SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_grado_level_codigo` (`codigo`),
  UNIQUE KEY `uq_org_grado_level_rank` (`orden_jerarquia`)
) ENGINE=InnoDB;

CREATE TABLE `organizacion_grados_academicos` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_empleado` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_nivel_academico` SMALLINT UNSIGNED NOT NULL,
  `nombre_programa` VARCHAR(255) NOT NULL,
  `siglas` VARCHAR(45) NULL,
  `fecha_obtencion` DATE NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_org_grado_empleado` (`id_empleado`, `id_nivel_academico`),
  CONSTRAINT `fk_org_grado_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `organizacion_empleados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_org_grado_level` FOREIGN KEY (`id_nivel_academico`) REFERENCES `organizacion_niveles_academicos` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Contexto delimitado Residencias
-- -----------------------------------------------------------------------------

CREATE TABLE `residencias_modalidades` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_modalidad_codigo` (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE `residencias_programas_educativos` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_modalidad` SMALLINT UNSIGNED NOT NULL,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(255) NOT NULL,
  `vigente_desde` DATE NULL,
  `vigente_hasta` DATE NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_programa_codigo` (`codigo`),
  KEY `idx_residencias_programa_modalidad` (`id_modalidad`),
  CONSTRAINT `fk_residencias_programa_modalidad` FOREIGN KEY (`id_modalidad`) REFERENCES `residencias_modalidades` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_programa_fechas` CHECK (`vigente_hasta` IS NULL OR `vigente_desde` IS NULL OR `vigente_hasta` >= `vigente_desde`)
) ENGINE=InnoDB;

CREATE TABLE `residencias_periodos` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(160) NOT NULL,
  `fecha_inicio` DATE NOT NULL,
  `fecha_fin` DATE NOT NULL,
  `duracion_meses` TINYINT UNSIGNED NOT NULL,
  `cantidad_reportes_parciales` TINYINT UNSIGNED NOT NULL,
  `cantidad_reportes_finales` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'PLANIFICADO',
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_periodo_codigo` (`codigo`),
  KEY `idx_residencias_periodo_fechas` (`fecha_inicio`, `fecha_fin`),
  CONSTRAINT `chk_residencias_periodo_fechas` CHECK (`fecha_fin` >= `fecha_inicio`),
  CONSTRAINT `chk_residencias_periodo_duracion` CHECK (`duracion_meses` IN (4, 5, 6)),
  CONSTRAINT `chk_residencias_periodo_reportes` CHECK (
    (`duracion_meses` IN (5, 6) AND `cantidad_reportes_parciales` = 3 AND `cantidad_reportes_finales` = 1)
    OR (`duracion_meses` = 4 AND `cantidad_reportes_parciales` = 2 AND `cantidad_reportes_finales` = 1)
  ),
  CONSTRAINT `chk_residencias_periodo_estado` CHECK (`estado` IN ('PLANIFICADO','ACTIVO','CERRADO','ARCHIVADO'))
) ENGINE=InnoDB;

CREATE TABLE `residencias_residentes` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_identidad` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Referencia logica a IAM',
  `id_programa` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `numero_control` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombres` VARCHAR(160) NOT NULL,
  `primer_apellido` VARCHAR(120) NOT NULL,
  `segundo_apellido` VARCHAR(120) NULL,
  `correo_institucional` VARCHAR(254) NULL,
  `domicilio` VARCHAR(500) NULL,
  `ciudad` VARCHAR(120) NULL,
  `numero_seguro_social` VARCHAR(30) NULL,
  `telefono` VARCHAR(30) NULL,
  `telefono_celular` VARCHAR(30) NULL,
  `curp` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `estado` VARCHAR(30) NOT NULL DEFAULT 'REGISTRO_INICIAL',
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `eliminado_en` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_residente_identidad` (`id_identidad`),
  UNIQUE KEY `uq_residencias_residente_control` (`numero_control`),
  UNIQUE KEY `uq_residencias_residente_curp` (`curp`),
  KEY `idx_residencias_residente_programa` (`id_programa`),
  CONSTRAINT `fk_residencias_residente_programa` FOREIGN KEY (`id_programa`) REFERENCES `residencias_programas_educativos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_residente_estado` CHECK (`estado` IN ('REGISTRO_INICIAL','ACTIVO','SUSPENDIDO','LIBERADO','ARCHIVADO'))
) ENGINE=InnoDB;

CREATE TABLE `residencias_sectores` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(120) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_sector_codigo` (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE `residencias_ramos` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(120) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_ramo_codigo` (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE `residencias_empresas` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_sector` SMALLINT UNSIGNED NULL,
  `id_ramo` SMALLINT UNSIGNED NULL,
  `razon_social` VARCHAR(255) NOT NULL,
  `rfc` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `mision` TEXT NULL,
  `domicilio` VARCHAR(500) NULL,
  `ciudad` VARCHAR(120) NULL,
  `codigo_postal` VARCHAR(12) NULL,
  `telefono` VARCHAR(30) NULL,
  `correo` VARCHAR(254) NULL,
  `fax` VARCHAR(30) NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `eliminado_en` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_empresa_rfc` (`rfc`),
  KEY `idx_residencias_empresa_nombre` (`razon_social`),
  KEY `idx_residencias_empresa_sector` (`id_sector`),
  KEY `idx_residencias_empresa_ramo` (`id_ramo`),
  CONSTRAINT `fk_residencias_empresa_sector` FOREIGN KEY (`id_sector`) REFERENCES `residencias_sectores` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_empresa_ramo` FOREIGN KEY (`id_ramo`) REFERENCES `residencias_ramos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_empresa_estado` CHECK (`estado` IN ('PENDIENTE','ACTIVO','INACTIVO','BLOQUEADO'))
) ENGINE=InnoDB;

CREATE TABLE `residencias_contactos_empresa` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_empresa` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombres` VARCHAR(160) NOT NULL,
  `primer_apellido` VARCHAR(120) NOT NULL,
  `segundo_apellido` VARCHAR(120) NULL,
  `nombre_puesto` VARCHAR(180) NULL,
  `nombre_grado` VARCHAR(120) NULL,
  `correo` VARCHAR(254) NULL,
  `telefono` VARCHAR(30) NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_residencias_contacto_empresa` (`id_empresa`, `activo`),
  CONSTRAINT `fk_residencias_contacto_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `residencias_empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `residencias_proyectos` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_residente` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_periodo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_empresa` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_programa` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(255) NOT NULL,
  `numero_vacantes` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `fecha_inicio` DATE NOT NULL,
  `fecha_fin` DATE NOT NULL,
  `estado` VARCHAR(30) NOT NULL DEFAULT 'BORRADOR',
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `eliminado_en` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_proyecto_residente_periodo` (`id_residente`, `id_periodo`),
  KEY `idx_residencias_proyecto_residente_estado` (`id_residente`, `estado`),
  KEY `idx_residencias_proyecto_empresa` (`id_empresa`),
  KEY `idx_residencias_proyecto_programa` (`id_programa`),
  KEY `idx_residencias_proyecto_fechas` (`fecha_inicio`, `fecha_fin`),
  CONSTRAINT `fk_residencias_proyecto_residente` FOREIGN KEY (`id_residente`) REFERENCES `residencias_residentes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_proyecto_periodo` FOREIGN KEY (`id_periodo`) REFERENCES `residencias_periodos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_proyecto_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `residencias_empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_proyecto_programa` FOREIGN KEY (`id_programa`) REFERENCES `residencias_programas_educativos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_proyecto_fechas` CHECK (`fecha_fin` >= `fecha_inicio`),
  CONSTRAINT `chk_residencias_proyecto_vacancies` CHECK (`numero_vacantes` > 0),
  CONSTRAINT `chk_residencias_proyecto_estado` CHECK (`estado` IN ('BORRADOR','ENVIADO','APROBADO','EN_PROGRESO','COMPLETADO','CANCELADO','ARCHIVADO'))
) ENGINE=InnoDB;

CREATE TABLE `residencias_asesores_internos_proyecto` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_proyecto` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_empleado` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica a Organizacion',
  `codigo_rol` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PRINCIPAL',
  `nombre_instantanea` VARCHAR(255) NOT NULL,
  `puesto_instantanea` VARCHAR(180) NULL,
  `grado_instantanea` VARCHAR(120) NULL,
  `fecha_inicio` DATE NOT NULL,
  `fecha_fin` DATE NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_interno_asesor` (`id_proyecto`, `id_empleado`, `fecha_inicio`),
  KEY `idx_residencias_interno_empleado` (`id_empleado`, `fecha_fin`),
  CONSTRAINT `fk_residencias_interno_proyecto` FOREIGN KEY (`id_proyecto`) REFERENCES `residencias_proyectos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_residencias_interno_fechas` CHECK (`fecha_fin` IS NULL OR `fecha_fin` >= `fecha_inicio`)
) ENGINE=InnoDB;

CREATE TABLE `residencias_asesores_externos_proyecto` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_proyecto` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_contacto_empresa` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo_rol` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PRINCIPAL',
  `nombre_instantanea` VARCHAR(255) NOT NULL,
  `puesto_instantanea` VARCHAR(180) NULL,
  `fecha_inicio` DATE NOT NULL,
  `fecha_fin` DATE NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_externo_asesor` (`id_proyecto`, `id_contacto_empresa`, `fecha_inicio`),
  KEY `idx_residencias_externo_contacto` (`id_contacto_empresa`, `fecha_fin`),
  CONSTRAINT `fk_residencias_externo_proyecto` FOREIGN KEY (`id_proyecto`) REFERENCES `residencias_proyectos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_residencias_externo_contacto` FOREIGN KEY (`id_contacto_empresa`) REFERENCES `residencias_contactos_empresa` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_externo_fechas` CHECK (`fecha_fin` IS NULL OR `fecha_fin` >= `fecha_inicio`)
) ENGINE=InnoDB;

CREATE TABLE `residencias_definiciones_requisito` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_programa` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `alcance_programa` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (COALESCE(`id_programa`, '00000000000000000000000000')) STORED,
  `codigo` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(255) NOT NULL,
  `etapa` VARCHAR(30) NOT NULL,
  `tipo_evidencia` VARCHAR(30) NOT NULL DEFAULT 'DOCUMENTO',
  `obligatorio` BOOLEAN NOT NULL DEFAULT TRUE,
  `orden_visualizacion` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `vigente_desde` DATE NULL,
  `vigente_hasta` DATE NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_requisito_codigo` (`codigo`, `alcance_programa`),
  KEY `idx_residencias_requisito_stage` (`etapa`, `activo`),
  CONSTRAINT `fk_residencias_requisito_programa` FOREIGN KEY (`id_programa`) REFERENCES `residencias_programas_educativos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_requisito_stage` CHECK (`etapa` IN ('PRERREQUISITO','REQUISITO','REPORTE_PARCIAL','REPORTE_FINAL','LIBERACION')),
  CONSTRAINT `chk_residencias_requisito_fechas` CHECK (`vigente_hasta` IS NULL OR `vigente_desde` IS NULL OR `vigente_hasta` >= `vigente_desde`)
) ENGINE=InnoDB;

CREATE TABLE `residencias_entregas_evidencia` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_requisito` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_residente` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_proyecto` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `id_alcance` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (COALESCE(`id_proyecto`, `id_residente`)) STORED,
  `id_archivo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica al almacenamiento compartido',
  `numero_version` SMALLINT UNSIGNED NOT NULL,
  `estado` VARCHAR(30) NOT NULL DEFAULT 'ENVIADO',
  `enviado_en` DATETIME(6) NOT NULL,
  `reemplazado_en` DATETIME(6) NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_entrega_version` (`id_requisito`, `id_alcance`, `numero_version`),
  UNIQUE KEY `uq_residencias_entrega_archivo` (`id_archivo`),
  KEY `idx_residencias_entrega_residente` (`id_residente`, `estado`),
  KEY `idx_residencias_entrega_proyecto` (`id_proyecto`, `estado`),
  CONSTRAINT `fk_residencias_entrega_requisito` FOREIGN KEY (`id_requisito`) REFERENCES `residencias_definiciones_requisito` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_entrega_residente` FOREIGN KEY (`id_residente`) REFERENCES `residencias_residentes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_entrega_proyecto` FOREIGN KEY (`id_proyecto`) REFERENCES `residencias_proyectos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_entrega_version` CHECK (`numero_version` > 0),
  CONSTRAINT `chk_residencias_entrega_estado` CHECK (`estado` IN ('ENVIADO','EN_REVISION','APROBADO','RECHAZADO','REEMPLAZADO','RETIRADO'))
) ENGINE=InnoDB;

CREATE TABLE `residencias_revisiones_evidencia` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_entrega` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_identidad_revisor` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica a IAM',
  `numero_revision` INT UNSIGNED NOT NULL,
  `estado_anterior` VARCHAR(30) NULL,
  `decision` VARCHAR(20) NOT NULL,
  `observaciones` TEXT NULL,
  `calificacion` DECIMAL(5,2) NULL,
  `decidido_en` DATETIME(6) NOT NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_revision_numero` (`id_entrega`, `numero_revision`),
  KEY `idx_residencias_revision_entrega` (`id_entrega`, `decidido_en`),
  KEY `idx_residencias_revision_revisor` (`id_identidad_revisor`, `decidido_en`),
  CONSTRAINT `fk_residencias_revision_entrega` FOREIGN KEY (`id_entrega`) REFERENCES `residencias_entregas_evidencia` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_residencias_revision_numero` CHECK (`numero_revision` > 0),
  CONSTRAINT `chk_residencias_revision_estado_anterior` CHECK (`estado_anterior` IS NULL OR `estado_anterior` IN ('ENVIADO','EN_REVISION','APROBADO','RECHAZADO','REEMPLAZADO','RETIRADO')),
  CONSTRAINT `chk_residencias_revision_decision` CHECK (`decision` IN ('APROBADO','RECHAZADO','CAMBIOS_SOLICITADOS')),
  CONSTRAINT `chk_residencias_revision_score` CHECK (`calificacion` IS NULL OR (`calificacion` >= 0 AND `calificacion` <= 100))
) ENGINE=InnoDB;

CREATE TABLE `residencias_reportes_avance` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_proyecto` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_entrega` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tipo_reporte` VARCHAR(20) NOT NULL,
  `numero_consecutivo` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `titulo` VARCHAR(255) NOT NULL,
  `entregado_en` DATETIME(6) NOT NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_reporte_consecutivo` (`id_proyecto`, `tipo_reporte`, `numero_consecutivo`),
  UNIQUE KEY `uq_residencias_reporte_entrega` (`id_entrega`),
  CONSTRAINT `fk_residencias_reporte_proyecto` FOREIGN KEY (`id_proyecto`) REFERENCES `residencias_proyectos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_reporte_entrega` FOREIGN KEY (`id_entrega`) REFERENCES `residencias_entregas_evidencia` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_reporte_tipo` CHECK (`tipo_reporte` IN ('PARCIAL','FINAL')),
  CONSTRAINT `chk_residencias_reporte_consecutivo` CHECK (`numero_consecutivo` > 0)
) ENGINE=InnoDB;

CREATE TABLE `residencias_liberaciones` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_proyecto` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_reporte_final` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `estado` VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
  `resolucion` VARCHAR(255) NULL,
  `observaciones` TEXT NULL,
  `id_identidad_resolutor` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Referencia logica a IAM',
  `resuelto_en` DATETIME(6) NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_liberacion_proyecto` (`id_proyecto`),
  UNIQUE KEY `uq_residencias_liberacion_final_reporte` (`id_reporte_final`),
  KEY `idx_residencias_liberacion_resolutor` (`id_identidad_resolutor`, `resuelto_en`),
  CONSTRAINT `fk_residencias_liberacion_proyecto` FOREIGN KEY (`id_proyecto`) REFERENCES `residencias_proyectos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_liberacion_reporte` FOREIGN KEY (`id_reporte_final`) REFERENCES `residencias_reportes_avance` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_liberacion_estado` CHECK (`estado` IN ('PENDIENTE','APROBADO','RECHAZADO','REVOCADO'))
) ENGINE=InnoDB;

CREATE TABLE `residencias_historial_liberaciones` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_liberacion` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `numero_cambio` INT UNSIGNED NOT NULL,
  `estado_anterior` VARCHAR(30) NULL,
  `estado_nuevo` VARCHAR(30) NOT NULL,
  `resolucion` VARCHAR(255) NULL,
  `observaciones` TEXT NULL,
  `id_identidad_actor` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica a IAM',
  `cambiado_en` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_historial_liberacion_cambio` (`id_liberacion`, `numero_cambio`),
  KEY `idx_residencias_historial_liberacion_fecha` (`id_liberacion`, `cambiado_en`),
  CONSTRAINT `fk_residencias_historial_liberacion` FOREIGN KEY (`id_liberacion`) REFERENCES `residencias_liberaciones` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_historial_liberacion_numero` CHECK (`numero_cambio` > 0),
  CONSTRAINT `chk_residencias_historial_liberacion_anterior` CHECK (`estado_anterior` IS NULL OR `estado_anterior` IN ('PENDIENTE','APROBADO','RECHAZADO','REVOCADO')),
  CONSTRAINT `chk_residencias_historial_liberacion_nuevo` CHECK (`estado_nuevo` IN ('PENDIENTE','APROBADO','RECHAZADO','REVOCADO'))
) ENGINE=InnoDB;

CREATE TABLE `residencias_constancias` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_proyecto` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_asignacion_asesor_interno` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `anio` SMALLINT UNSIGNED NOT NULL,
  `numero_periodo` TINYINT UNSIGNED NOT NULL,
  `numero_consecutivo` INT UNSIGNED NOT NULL,
  `folio` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `rango_inicio` DATE NULL,
  `rango_fin` DATE NULL,
  `id_identidad_generador` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica a IAM',
  `id_documento_emitido` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `generado_en` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_constancia_folio` (`folio`),
  UNIQUE KEY `uq_residencias_constancia_consecutivo` (`anio`, `numero_periodo`, `numero_consecutivo`),
  UNIQUE KEY `uq_residencias_constancia_documento_emitido` (`id_documento_emitido`),
  KEY `idx_residencias_constancia_proyecto` (`id_proyecto`),
  CONSTRAINT `fk_residencias_constancia_proyecto` FOREIGN KEY (`id_proyecto`) REFERENCES `residencias_proyectos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_constancia_asesor` FOREIGN KEY (`id_asignacion_asesor_interno`) REFERENCES `residencias_asesores_internos_proyecto` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_constancia_period` CHECK (`numero_periodo` BETWEEN 1 AND 12),
  CONSTRAINT `chk_residencias_constancia_fechas` CHECK (`rango_fin` IS NULL OR `rango_inicio` IS NULL OR `rango_fin` >= `rango_inicio`)
) ENGINE=InnoDB;

CREATE TABLE `residencias_plantillas_documento` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(160) NOT NULL,
  `descripcion` TEXT NULL,
  `id_archivo_plantilla` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Referencia logica al almacenamiento compartido',
  `numero_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_plantilla_version` (`codigo`, `numero_version`)
) ENGINE=InnoDB;

CREATE TABLE `residencias_recursos_plantilla` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_plantilla` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_archivo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica al almacenamiento compartido',
  `rol_recurso` VARCHAR(60) NOT NULL,
  `orden_visualizacion` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_plantilla_recurso` (`id_plantilla`, `id_archivo`),
  CONSTRAINT `fk_residencias_plantilla_recurso_plantilla` FOREIGN KEY (`id_plantilla`) REFERENCES `residencias_plantillas_documento` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `residencias_documentos_emitidos` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_residente` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_proyecto` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `id_plantilla` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `tipo_documento` VARCHAR(50) NOT NULL,
  `numero_version_plantilla` SMALLINT UNSIGNED NULL,
  `datos_emision` JSON NOT NULL COMMENT 'Instantanea inmutable de todos los campos usados al emitir',
  `hash_datos_emision` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_archivo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica al almacenamiento compartido',
  `id_identidad_emisor` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica a IAM',
  `emitido_en` DATETIME(6) NOT NULL,
  `anulado_en` DATETIME(6) NULL,
  `motivo_anulacion` VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_residencias_documento_emitido_archivo` (`id_archivo`),
  KEY `idx_residencias_documento_emitido_residente` (`id_residente`, `emitido_en`),
  KEY `idx_residencias_documento_emitido_proyecto` (`id_proyecto`, `emitido_en`),
  CONSTRAINT `fk_residencias_documento_emitido_residente` FOREIGN KEY (`id_residente`) REFERENCES `residencias_residentes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_documento_emitido_proyecto` FOREIGN KEY (`id_proyecto`) REFERENCES `residencias_proyectos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_residencias_documento_emitido_plantilla` FOREIGN KEY (`id_plantilla`) REFERENCES `residencias_plantillas_documento` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_residencias_documento_emitido_tipo` CHECK (`tipo_documento` IN ('SOLICITUD_RESIDENCIA','CARTA_PRESENTACION_FORMAL','CARTA_PRESENTACION_INFORMAL','CONSTANCIA_ASESORIA','OTRO')),
  CONSTRAINT `chk_residencias_documento_emitido_anulacion` CHECK (`anulado_en` IS NULL OR `motivo_anulacion` IS NOT NULL)
) ENGINE=InnoDB;

ALTER TABLE `residencias_constancias`
  ADD CONSTRAINT `fk_residencias_constancia_documento_emitido`
  FOREIGN KEY (`id_documento_emitido`) REFERENCES `residencias_documentos_emitidos` (`id`) ON DELETE RESTRICT;

-- -----------------------------------------------------------------------------
-- Contexto delimitado Publicaciones
-- -----------------------------------------------------------------------------

CREATE TABLE `publicaciones_avisos` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tipo_aviso` VARCHAR(30) NOT NULL,
  `titulo` VARCHAR(255) NOT NULL,
  `descripcion` TEXT NOT NULL,
  `requisitos` TEXT NULL,
  `url_externa` VARCHAR(2048) NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'BORRADOR',
  `id_identidad_publicador` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Referencia logica a IAM',
  `publicado_en` DATETIME(6) NULL,
  `fecha_cierre` DATE NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `eliminado_en` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_publicaciones_aviso_public` (`estado`, `publicado_en`, `fecha_cierre`),
  CONSTRAINT `chk_publicaciones_aviso_tipo` CHECK (`tipo_aviso` IN ('RESIDENCIA','SERVICIO_SOCIAL','EMPLEO','GENERAL')),
  CONSTRAINT `chk_publicaciones_aviso_estado` CHECK (`estado` IN ('BORRADOR','PROGRAMADO','PUBLICADO','RETIRADO','CERRADO'))
) ENGINE=InnoDB;

CREATE TABLE `publicaciones_recursos_aviso` (
  `id_aviso` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_archivo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica al almacenamiento compartido',
  `rol_recurso` VARCHAR(40) NOT NULL DEFAULT 'IMAGEN',
  `orden_visualizacion` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_aviso`, `id_archivo`),
  CONSTRAINT `fk_publicaciones_recurso_aviso` FOREIGN KEY (`id_aviso`) REFERENCES `publicaciones_avisos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Contexto delimitado Planificacion academica
-- -----------------------------------------------------------------------------

CREATE TABLE `planificacion_semestres` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `fecha_inicio` DATE NOT NULL,
  `fecha_fin` DATE NOT NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'PLANIFICADO',
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planificacion_semestre_codigo` (`codigo`),
  KEY `idx_planificacion_semestre_fechas` (`fecha_inicio`, `fecha_fin`),
  CONSTRAINT `chk_planificacion_semestre_fechas` CHECK (`fecha_fin` >= `fecha_inicio`),
  CONSTRAINT `chk_planificacion_semestre_estado` CHECK (`estado` IN ('PLANIFICADO','ACTIVO','CERRADO','ARCHIVADO'))
) ENGINE=InnoDB;

CREATE TABLE `planificacion_carreras` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(255) NOT NULL,
  `nombre_corto` VARCHAR(80) NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planificacion_carrera_codigo` (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE `planificacion_especialidades` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_carrera` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(255) NOT NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planificacion_especialidad_codigo` (`id_carrera`, `codigo`),
  CONSTRAINT `fk_planificacion_especialidad_carrera` FOREIGN KEY (`id_carrera`) REFERENCES `planificacion_carreras` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `planificacion_asignaturas` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(255) NOT NULL,
  `satca` VARCHAR(30) NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planificacion_asignatura_codigo` (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE `planificacion_reticulas` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_carrera` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_especialidad` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `id_asignatura` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planificacion_reticula` (`id_carrera`, `id_especialidad`, `id_asignatura`),
  KEY `idx_planificacion_reticula_asignatura` (`id_asignatura`),
  CONSTRAINT `fk_planificacion_reticula_carrera` FOREIGN KEY (`id_carrera`) REFERENCES `planificacion_carreras` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_planificacion_reticula_especialidad` FOREIGN KEY (`id_especialidad`) REFERENCES `planificacion_especialidades` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_planificacion_reticula_asignatura` FOREIGN KEY (`id_asignatura`) REFERENCES `planificacion_asignaturas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `planificacion_grupos` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_carrera` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_semestre` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(80) NOT NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planificacion_grupo` (`id_semestre`, `id_carrera`, `nombre`),
  KEY `idx_planificacion_grupo_carrera` (`id_carrera`),
  CONSTRAINT `fk_planificacion_grupo_carrera` FOREIGN KEY (`id_carrera`) REFERENCES `planificacion_carreras` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_planificacion_grupo_semestre` FOREIGN KEY (`id_semestre`) REFERENCES `planificacion_semestres` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `planificacion_laboratorios` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_carrera` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(160) NOT NULL,
  `capacidad` SMALLINT UNSIGNED NOT NULL,
  `id_empleado_responsable` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Referencia logica a Organizacion',
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planificacion_laboratorio_codigo` (`codigo`),
  KEY `idx_planificacion_laboratorio_carrera` (`id_carrera`),
  CONSTRAINT `fk_planificacion_laboratorio_carrera` FOREIGN KEY (`id_carrera`) REFERENCES `planificacion_carreras` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_planificacion_laboratorio_capacidad` CHECK (`capacidad` > 0),
  CONSTRAINT `chk_planificacion_laboratorio_estado` CHECK (`estado` IN ('ACTIVO','MANTENIMIENTO','INACTIVO','ARCHIVADO'))
) ENGINE=InnoDB;

CREATE TABLE `planificacion_horarios` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_semestre` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_laboratorio` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dia_semana` TINYINT UNSIGNED NOT NULL,
  `inicio_en` TIME NOT NULL,
  `fin_en` TIME NOT NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planificacion_horario_franja` (`id_semestre`, `id_laboratorio`, `dia_semana`, `inicio_en`, `fin_en`),
  KEY `idx_planificacion_horario_laboratorios` (`id_laboratorio`, `dia_semana`, `inicio_en`, `fin_en`),
  CONSTRAINT `fk_planificacion_horario_semestre` FOREIGN KEY (`id_semestre`) REFERENCES `planificacion_semestres` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_planificacion_horario_laboratorio` FOREIGN KEY (`id_laboratorio`) REFERENCES `planificacion_laboratorios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_planificacion_horario_day` CHECK (`dia_semana` BETWEEN 1 AND 7),
  CONSTRAINT `chk_planificacion_horario_time` CHECK (`fin_en` > `inicio_en`)
) ENGINE=InnoDB;

CREATE TABLE `planificacion_tipos_dia_inhabil` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planificacion_inhabilidad_tipo_codigo` (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE `planificacion_dias_inhabiles` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_tipo_dia_inhabil` SMALLINT UNSIGNED NOT NULL,
  `id_laboratorio` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `nombre` VARCHAR(180) NOT NULL,
  `inicio_en` DATETIME(6) NOT NULL,
  `fin_en` DATETIME(6) NOT NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_planificacion_inhabilidad_intervalo` (`id_laboratorio`, `inicio_en`, `fin_en`),
  CONSTRAINT `fk_planificacion_inhabilidad_tipo` FOREIGN KEY (`id_tipo_dia_inhabil`) REFERENCES `planificacion_tipos_dia_inhabil` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_planificacion_inhabilidad_laboratorio` FOREIGN KEY (`id_laboratorio`) REFERENCES `planificacion_laboratorios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_planificacion_inhabilidad_intervalo` CHECK (`fin_en` > `inicio_en`)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Contexto delimitado Solicitudes de laboratorio
-- Las referencias a Planificacion e IAM son logicas: no hay FK entre contextos.
-- -----------------------------------------------------------------------------

CREATE TABLE `laboratorios_solicitudes` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_identidad_solicitante` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_laboratorio` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_semestre` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tipo_solicitud` VARCHAR(30) NOT NULL,
  `titulo` VARCHAR(255) NOT NULL,
  `proposito` TEXT NULL,
  `inicio_en` DATETIME(6) NOT NULL,
  `fin_en` DATETIME(6) NOT NULL,
  `numero_asistentes` SMALLINT UNSIGNED NULL,
  `estado` VARCHAR(30) NOT NULL DEFAULT 'BORRADOR',
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `enviado_en` DATETIME(6) NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `cancelado_en` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_laboratorios_solicitud_calendar` (`id_laboratorio`, `estado`, `inicio_en`, `fin_en`),
  KEY `idx_laboratorios_solicitud_solicitante` (`id_identidad_solicitante`, `creado_en`),
  KEY `idx_laboratorios_solicitud_semestre` (`id_semestre`),
  CONSTRAINT `chk_laboratorios_solicitud_tipo` CHECK (`tipo_solicitud` IN ('PRACTICA','VARIA','EXTRAORDINARIA')),
  CONSTRAINT `chk_laboratorios_solicitud_intervalo` CHECK (`fin_en` > `inicio_en`),
  CONSTRAINT `chk_laboratorios_solicitud_asistentes` CHECK (`numero_asistentes` IS NULL OR `numero_asistentes` > 0),
  CONSTRAINT `chk_laboratorios_solicitud_estado` CHECK (`estado` IN ('BORRADOR','PENDIENTE','AUTORIZADO','RECHAZADO','CANCELADO','COMPLETADO'))
) ENGINE=InnoDB;

CREATE TABLE `laboratorios_detalles_practica` (
  `id_solicitud` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_carrera` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_asignatura` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_grupo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre_practica` VARCHAR(255) NOT NULL,
  `numero_practica` VARCHAR(40) NULL,
  PRIMARY KEY (`id_solicitud`),
  CONSTRAINT `fk_laboratorios_practica_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `laboratorios_solicitudes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `laboratorios_detalles_varios` (
  `id_solicitud` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo_tipo_uso` VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `descripcion` TEXT NOT NULL,
  PRIMARY KEY (`id_solicitud`),
  CONSTRAINT `fk_laboratorios_varios_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `laboratorios_solicitudes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `laboratorios_detalles_extraordinarios` (
  `id_solicitud` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `justificacion` TEXT NOT NULL,
  `id_empleado_solicitante` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Referencia logica a Organizacion',
  PRIMARY KEY (`id_solicitud`),
  CONSTRAINT `fk_laboratorios_extraordinarios_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `laboratorios_solicitudes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `laboratorios_decisiones_solicitud` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_solicitud` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_identidad_decisor` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `decision` VARCHAR(20) NOT NULL,
  `observaciones` TEXT NULL,
  `decidido_en` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_laboratorios_decision_solicitud` (`id_solicitud`, `decidido_en`),
  KEY `idx_laboratorios_decision_actor` (`id_identidad_decisor`, `decidido_en`),
  CONSTRAINT `fk_laboratorios_decision_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `laboratorios_solicitudes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_laboratorios_decision` CHECK (`decision` IN ('AUTORIZADO','RECHAZADO','CAMBIOS_SOLICITADOS','CANCELADO'))
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Contexto delimitado Inventarios
-- Las referencias a Planificacion, Organizacion, IAM y Compartido son logicas.
-- -----------------------------------------------------------------------------

CREATE TABLE `inventario_inventarios` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_laboratorio` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  `inicializado_en` DATETIME(6) NOT NULL,
  `id_identidad_inicializador` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventario_laboratorio` (`id_laboratorio`),
  CONSTRAINT `chk_inventario_estado` CHECK (`estado` IN ('ACTIVO','CONGELADO','ARCHIVADO'))
) ENGINE=InnoDB;

CREATE TABLE `inventario_ubicaciones` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_inventario` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_padre` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `alcance_padre` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (COALESCE(`id_padre`, '00000000000000000000000000')) STORED,
  `nombre` VARCHAR(160) NOT NULL,
  `nombre_normalizado` VARCHAR(160) NOT NULL,
  `tipo_ubicacion` VARCHAR(40) NOT NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventario_ubicacion_hermano` (`id_inventario`, `alcance_padre`, `nombre_normalizado`),
  KEY `idx_inventario_ubicacion_padre` (`id_padre`),
  CONSTRAINT `fk_inventario_ubicacion_inventario` FOREIGN KEY (`id_inventario`) REFERENCES `inventario_inventarios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inventario_ubicacion_padre` FOREIGN KEY (`id_padre`) REFERENCES `inventario_ubicaciones` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventario_ubicacion_not_self` CHECK (`id_padre` IS NULL OR `id_padre` <> `id`)
) ENGINE=InnoDB;

CREATE TABLE `inventario_articulos` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_inventario` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_ubicacion` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `tipo_articulo` VARCHAR(30) NOT NULL,
  `codigo_inventario` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `nombre` VARCHAR(255) NOT NULL,
  `descripcion` TEXT NULL,
  `marca` VARCHAR(120) NULL,
  `modelo` VARCHAR(120) NULL,
  `numero_serie` VARCHAR(160) NULL,
  `cantidad` DECIMAL(18,4) NOT NULL DEFAULT 1,
  `codigo_unidad` VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PIEZA',
  `estado` VARCHAR(30) NOT NULL DEFAULT 'ACTIVO',
  `fecha_adquisicion` DATE NULL,
  `fecha_caducidad` DATE NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `retirado_en` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventario_articulo_codigo` (`id_inventario`, `codigo_inventario`),
  KEY `idx_inventario_articulo_ubicacion` (`id_ubicacion`, `estado`),
  KEY `idx_inventario_articulo_tipo_estado` (`id_inventario`, `tipo_articulo`, `estado`),
  KEY `idx_inventario_articulo_expiracion` (`fecha_caducidad`, `estado`),
  CONSTRAINT `fk_inventario_articulo_inventario` FOREIGN KEY (`id_inventario`) REFERENCES `inventario_inventarios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inventario_articulo_ubicacion` FOREIGN KEY (`id_ubicacion`) REFERENCES `inventario_ubicaciones` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventario_articulo_tipo` CHECK (`tipo_articulo` IN ('EQUIPO','MATERIAL','REACTIVO','SOFTWARE','INFRAESTRUCTURA')),
  CONSTRAINT `chk_inventario_articulo_cantidad` CHECK (`cantidad` >= 0),
  CONSTRAINT `chk_inventario_articulo_fechas` CHECK (`fecha_caducidad` IS NULL OR `fecha_adquisicion` IS NULL OR `fecha_caducidad` >= `fecha_adquisicion`),
  CONSTRAINT `chk_inventario_articulo_estado` CHECK (`estado` IN ('ACTIVO','EXISTENCIA_BAJA','CADUCADO','MANTENIMIENTO','DANADO','AGOTADO','RETIRADO'))
) ENGINE=InnoDB;

CREATE TABLE `inventario_detalles_equipo` (
  `id_articulo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `etiqueta_activo` VARCHAR(100) NULL,
  `nombre_condicion` VARCHAR(80) NULL,
  `fecha_proximo_mantenimiento` DATE NULL,
  PRIMARY KEY (`id_articulo`),
  UNIQUE KEY `uq_inventario_equipo_recurso_tag` (`etiqueta_activo`),
  CONSTRAINT `fk_inventario_equipo_articulo` FOREIGN KEY (`id_articulo`) REFERENCES `inventario_articulos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `inventario_detalles_material` (
  `id_articulo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `existencia_minima` DECIMAL(18,4) NULL,
  `punto_reorden` DECIMAL(18,4) NULL,
  PRIMARY KEY (`id_articulo`),
  CONSTRAINT `fk_inventario_material_articulo` FOREIGN KEY (`id_articulo`) REFERENCES `inventario_articulos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_inventario_material_min` CHECK (`existencia_minima` IS NULL OR `existencia_minima` >= 0),
  CONSTRAINT `chk_inventario_material_reorder` CHECK (`punto_reorden` IS NULL OR `punto_reorden` >= 0)
) ENGINE=InnoDB;

CREATE TABLE `inventario_detalles_reactivo` (
  `id_articulo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `numero_cas` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `concentracion` VARCHAR(80) NULL,
  `clase_peligro` VARCHAR(100) NULL,
  `numero_lote` VARCHAR(100) NULL,
  `existencia_minima` DECIMAL(18,4) NULL,
  PRIMARY KEY (`id_articulo`),
  KEY `idx_inventario_reactivo_cas` (`numero_cas`),
  CONSTRAINT `fk_inventario_reactivo_articulo` FOREIGN KEY (`id_articulo`) REFERENCES `inventario_articulos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_inventario_reactivo_min` CHECK (`existencia_minima` IS NULL OR `existencia_minima` >= 0)
) ENGINE=InnoDB;

CREATE TABLE `inventario_detalles_software` (
  `id_articulo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tipo_licencia` VARCHAR(80) NULL,
  `clave_licencia_cifrada` TEXT NULL,
  `numero_licencias` INT UNSIGNED NULL,
  `nombre_proveedor` VARCHAR(160) NULL,
  PRIMARY KEY (`id_articulo`),
  CONSTRAINT `fk_inventario_software_articulo` FOREIGN KEY (`id_articulo`) REFERENCES `inventario_articulos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_inventario_software_seats` CHECK (`numero_licencias` IS NULL OR `numero_licencias` > 0)
) ENGINE=InnoDB;

CREATE TABLE `inventario_detalles_infraestructura` (
  `id_articulo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tipo_sistema` VARCHAR(100) NULL,
  `especificacion_tecnica` TEXT NULL,
  PRIMARY KEY (`id_articulo`),
  CONSTRAINT `fk_inventario_infraestructura_articulo` FOREIGN KEY (`id_articulo`) REFERENCES `inventario_articulos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `inventario_movimientos` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_articulo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tipo_movimiento` VARCHAR(40) NOT NULL,
  `variacion_cantidad` DECIMAL(18,4) NULL,
  `id_ubicacion_origen` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `id_ubicacion_destino` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `motivo` VARCHAR(500) NULL,
  `id_identidad_actor` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica a IAM',
  `ocurrido_en` DATETIME(6) NOT NULL,
  `metadatos` JSON NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inventario_movimiento_articulo` (`id_articulo`, `ocurrido_en`),
  KEY `idx_inventario_movimiento_actor` (`id_identidad_actor`, `ocurrido_en`),
  CONSTRAINT `fk_inventario_movimiento_articulo` FOREIGN KEY (`id_articulo`) REFERENCES `inventario_articulos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inventario_movimiento_origen_ubicacion` FOREIGN KEY (`id_ubicacion_origen`) REFERENCES `inventario_ubicaciones` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inventario_movimiento_destino_ubicacion` FOREIGN KEY (`id_ubicacion_destino`) REFERENCES `inventario_ubicaciones` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventario_movimiento_tipo` CHECK (`tipo_movimiento` IN ('REGISTRADO','AJUSTADO','REUBICADO','ESTADO_CAMBIADO','RETIRADO','RESTAURADO'))
) ENGINE=InnoDB;

CREATE TABLE `inventario_incidencias` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_articulo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_identidad_reportante` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tipo_incidencia` VARCHAR(60) NOT NULL,
  `severidad` VARCHAR(20) NOT NULL,
  `descripcion` TEXT NOT NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ABIERTA',
  `reportado_en` DATETIME(6) NOT NULL,
  `id_identidad_resolutor` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `resuelto_en` DATETIME(6) NULL,
  `resolucion` TEXT NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actualizado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_inventario_incidencia_articulo` (`id_articulo`, `estado`, `reportado_en`),
  KEY `idx_inventario_incidencia_reportante` (`id_identidad_reportante`, `reportado_en`),
  CONSTRAINT `fk_inventario_incidencia_articulo` FOREIGN KEY (`id_articulo`) REFERENCES `inventario_articulos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventario_incidencia_severidad` CHECK (`severidad` IN ('BAJA','MEDIA','ALTA','CRITICA')),
  CONSTRAINT `chk_inventario_incidencia_estado` CHECK (`estado` IN ('ABIERTA','EN_PROGRESO','RESUELTA','CANCELADO')),
  CONSTRAINT `chk_inventario_incidencia_resolucion` CHECK ((`estado` <> 'RESUELTA') OR (`resuelto_en` IS NOT NULL AND `resolucion` IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE `inventario_politicas_abc` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_inventario` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `umbral_a` DECIMAL(5,2) NOT NULL,
  `umbral_b` DECIMAL(5,2) NOT NULL,
  `vigente_desde` DATE NOT NULL,
  `vigente_hasta` DATE NULL,
  `id_identidad_creador` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventario_abc_vigencia` (`id_inventario`, `vigente_desde`),
  CONSTRAINT `fk_inventario_abc_inventario` FOREIGN KEY (`id_inventario`) REFERENCES `inventario_inventarios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventario_abc_umbrales` CHECK (`umbral_a` > 0 AND `umbral_a` < `umbral_b` AND `umbral_b` <= 100),
  CONSTRAINT `chk_inventario_abc_fechas` CHECK (`vigente_hasta` IS NULL OR `vigente_hasta` >= `vigente_desde`)
) ENGINE=InnoDB;

CREATE TABLE `inventario_cortes_mensuales` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_inventario` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_politica_abc` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `periodo` CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'YYYY-MM',
  `estado` VARCHAR(20) NOT NULL DEFAULT 'GENERANDO',
  `id_identidad_generador` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `generado_en` DATETIME(6) NOT NULL,
  `cerrado_en` DATETIME(6) NULL,
  `hash_instantanea` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventario_corte_period` (`id_inventario`, `periodo`),
  CONSTRAINT `fk_inventario_corte_inventario` FOREIGN KEY (`id_inventario`) REFERENCES `inventario_inventarios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inventario_corte_abc` FOREIGN KEY (`id_politica_abc`) REFERENCES `inventario_politicas_abc` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventario_corte_period` CHECK (`periodo` REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
  CONSTRAINT `chk_inventario_corte_estado` CHECK (`estado` IN ('GENERANDO','CERRADO','FALLIDO','ANULADO'))
) ENGINE=InnoDB;

CREATE TABLE `inventario_detalles_corte_mensual` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_corte` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_articulo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tipo_articulo_instantanea` VARCHAR(30) NOT NULL,
  `nombre_instantanea` VARCHAR(255) NOT NULL,
  `cantidad_instantanea` DECIMAL(18,4) NOT NULL,
  `unidad_instantanea` VARCHAR(30) NOT NULL,
  `clase_abc` CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `datos_instantanea` JSON NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventario_corte_articulo` (`id_corte`, `id_articulo`),
  KEY `idx_inventario_corte_detalle_articulo` (`id_articulo`),
  CONSTRAINT `fk_inventario_corte_detalle_corte` FOREIGN KEY (`id_corte`) REFERENCES `inventario_cortes_mensuales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_inventario_corte_detalle_cantidad` CHECK (`cantidad_instantanea` >= 0),
  CONSTRAINT `chk_inventario_corte_detalle_abc` CHECK (`clase_abc` IS NULL OR `clase_abc` IN ('A','B','C'))
) ENGINE=InnoDB;

CREATE TABLE `inventario_permisos_acceso` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_laboratorio` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_identidad` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nivel_acceso` VARCHAR(20) NOT NULL,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  `id_identidad_otorgante` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `otorgado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `revocado_en` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventario_acceso` (`id_laboratorio`, `id_identidad`),
  KEY `idx_inventario_acceso_identidad` (`id_identidad`, `activo`),
  CONSTRAINT `chk_inventario_acceso_level` CHECK (`nivel_acceso` IN ('LECTOR','EDITOR','ADMIN'))
) ENGINE=InnoDB;

CREATE TABLE `inventario_documentos_laboratorio` (
  `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_laboratorio` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `id_archivo` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Referencia logica al almacenamiento compartido',
  `tipo_documento` VARCHAR(40) NOT NULL DEFAULT 'REGLAMENTO',
  `numero_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  `id_identidad_carga` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `creado_en` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventario_laboratorios_documento_version` (`id_laboratorio`, `tipo_documento`, `numero_version`),
  UNIQUE KEY `uq_inventario_laboratorios_documento_archivo` (`id_archivo`)
) ENGINE=InnoDB;

-- Semillas estables de plataforma. Los catalogos de negocio se migran por separado.
INSERT INTO `compartido_politicas_retencion`
  (`id`, `codigo`, `nombre`, `meses_archivo_circular`, `meses_archivo_historico`, `meses_para_depurar`, `depuracion_automatica`, `activo`)
VALUES
  ('01J00000000000000000000010', 'GENERAL_2_5_10', 'Conservacion institucional general 2/5/10', 24, 60, 120, FALSE, TRUE);

INSERT INTO `iam_permisos` (`id`, `codigo`, `descripcion`) VALUES
  ('01J00000000000000000000001', 'residencias.evidencias.revisar', 'Revisar evidencias de residencias'),
  ('01J00000000000000000000002', 'residencias.liberaciones.resolver', 'Aprobar o rechazar liberaciones de residencias'),
  ('01J00000000000000000000003', 'laboratorios.solicitudes.revisar', 'Revisar solicitudes de laboratorio'),
  ('01J00000000000000000000004', 'inventarios.articulos.actualizar', 'Actualizar articulos de inventario'),
  ('01J00000000000000000000005', 'inventarios.administrar', 'Administrar acceso contextual a inventarios'),
  ('01J00000000000000000000006', 'publicaciones.administrar', 'Administrar avisos publicos');

-- Fin del esquema base. Laravel crea posteriormente su propia tabla de migraciones.



