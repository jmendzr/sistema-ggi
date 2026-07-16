-- ============================================================
--  GGI SISTEMA DE GESTIÓN DOCUMENTARIA
--  Group Global ISO S.A.C.
--  Versión: 1.0
--  Fecha: 2025
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "-05:00"; -- Lima, Perú
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE DATABASE IF NOT EXISTS `ggi_sistema`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `ggi_sistema`;

-- ============================================================
--  TABLAS MAESTRAS (sin dependencias)
-- ============================================================

-- Usuarios del sistema
CREATE TABLE `usuarios` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`         VARCHAR(100) NOT NULL,
  `apellido`       VARCHAR(100) NOT NULL,
  `email`          VARCHAR(150) NOT NULL UNIQUE,
  `password_hash`  VARCHAR(255) NOT NULL,
  `rol`            ENUM('gerente_general','gerente_operaciones','ventas','delivery') NOT NULL,
  `activo`         TINYINT(1) NOT NULL DEFAULT 1,
  `ultimo_acceso`  TIMESTAMP NULL DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Normas ISO (ISO 9001, ISO 14001, etc.)
CREATE TABLE `normas_iso` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo`      VARCHAR(30) NOT NULL UNIQUE,
  `nombre`      VARCHAR(150) NOT NULL,
  `descripcion` TEXT,
  `activo`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Casas certificadoras (QRO, HAWK EYE, QFS, UKS, etc.)
CREATE TABLE `casas_certificadoras` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`      VARCHAR(100) NOT NULL,
  `pais`        VARCHAR(80),
  `contacto`    VARCHAR(150),
  `telefono`    VARCHAR(30),
  `email`       VARCHAR(150),
  `activo`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agencias de envío
CREATE TABLE `agencias_envio` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     VARCHAR(100) NOT NULL,
  `telefono`   VARCHAR(30),
  `web`        VARCHAR(150),
  `activo`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estados de envío
CREATE TABLE `estados_envio` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`      VARCHAR(80) NOT NULL,
  `descripcion` VARCHAR(200),
  `color_hex`   VARCHAR(7) DEFAULT '#6B7280',
  `activo`      TINYINT(1) NOT NULL DEFAULT 1,
  `orden`       TINYINT UNSIGNED DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  CLIENTES
-- ============================================================

CREATE TABLE `clientes` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo_interno`      VARCHAR(20) NOT NULL UNIQUE,        -- GGI-2025-001
  `ruc`                 CHAR(11) NOT NULL UNIQUE,
  `razon_social`        VARCHAR(200) NOT NULL,
  `direccion`           VARCHAR(300),
  `ubigeo`              VARCHAR(6),
  `departamento`        VARCHAR(80),
  `provincia`           VARCHAR(80),
  `distrito`            VARCHAR(80),
  `contacto_nombre`     VARCHAR(100),
  `contacto_apellido`   VARCHAR(100),
  `contacto_telefono`   VARCHAR(20),
  `contacto_email`      VARCHAR(150),
  `contacto_cargo`      VARCHAR(100),
  `fecha_contrato`      DATE,
  `estado`              ENUM('activo','inactivo','prospecto') NOT NULL DEFAULT 'activo',
  `notas`               TEXT,
  `creado_por`          INT UNSIGNED NOT NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ruc` (`ruc`),
  KEY `idx_razon_social` (`razon_social`),
  CONSTRAINT `fk_clientes_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ISOs adquiridas por cliente
CREATE TABLE `cliente_isos` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente_id`       INT UNSIGNED NOT NULL,
  `norma_iso_id`     INT UNSIGNED NOT NULL,
  `cantidad`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `fecha_adquisicion` DATE NOT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cliente_norma` (`cliente_id`, `norma_iso_id`),
  CONSTRAINT `fk_ci_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ci_norma`   FOREIGN KEY (`norma_iso_id`) REFERENCES `normas_iso` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documentos del cliente (versionados)
CREATE TABLE `documentos_cliente` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente_id`     INT UNSIGNED NOT NULL,
  `tipo_documento` ENUM('ficha_ruc','copia_literal','acta_alcance','voucher_inicial','voucher_final') NOT NULL,
  `nombre_archivo` VARCHAR(255) NOT NULL,
  `ruta_archivo`   VARCHAR(500) NOT NULL,
  `mime_type`      VARCHAR(100),
  `tamano_bytes`   INT UNSIGNED,
  `version`        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `es_vigente`     TINYINT(1) NOT NULL DEFAULT 1,
  `subido_por`     INT UNSIGNED NOT NULL,
  `uploaded_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_cliente` (`cliente_id`, `tipo_documento`, `es_vigente`),
  CONSTRAINT `fk_doc_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_doc_usuario` FOREIGN KEY (`subido_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comunicaciones internas por cliente (mini-feed)
CREATE TABLE `comunicaciones_cliente` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente_id`  INT UNSIGNED NOT NULL,
  `usuario_id`  INT UNSIGNED NOT NULL,
  `mensaje`     TEXT NOT NULL,
  `tipo`        ENUM('nota','alerta','seguimiento','llamada','reunion') NOT NULL DEFAULT 'nota',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_com_cliente` (`cliente_id`),
  CONSTRAINT `fk_com_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_com_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  COTIZACIONES / PROFORMAS
-- ============================================================

CREATE TABLE `cotizaciones` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo`          VARCHAR(20) NOT NULL UNIQUE,            -- COT-2025-001
  `cliente_id`      INT UNSIGNED NOT NULL,
  `estado`          ENUM('borrador','enviada','aceptada','rechazada','vencida') NOT NULL DEFAULT 'borrador',
  `monto_total`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `moneda`          ENUM('PEN','USD') NOT NULL DEFAULT 'PEN',
  `fecha_emision`   DATE NOT NULL,
  `fecha_vencimiento` DATE,
  `notas`           TEXT,
  `creado_por`      INT UNSIGNED NOT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_cot_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_cot_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Detalle de cotización (qué ISOs incluye)
CREATE TABLE `cotizaciones_detalle` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cotizacion_id`   INT UNSIGNED NOT NULL,
  `norma_iso_id`    INT UNSIGNED NOT NULL,
  `cantidad`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `precio_unitario` DECIMAL(10,2) NOT NULL,
  `subtotal`        DECIMAL(10,2) NOT NULL,
  `descripcion`     VARCHAR(300),
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_cd_cotizacion` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cd_norma`      FOREIGN KEY (`norma_iso_id`) REFERENCES `normas_iso` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  CERTIFICADOS
-- ============================================================

CREATE TABLE `certificados` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente_id`            INT UNSIGNED NOT NULL,
  `norma_iso_id`          INT UNSIGNED NOT NULL,
  `casa_certificadora_id` INT UNSIGNED NOT NULL,
  `numero_certificado`    VARCHAR(100),
  `alcance`               TEXT,
  `fecha_emision`         DATE NOT NULL,
  `fecha_vencimiento`     DATE NOT NULL,               -- Calculado: emision + 3 años
  `fecha_auditoria_1`     DATE,                        -- emision + 1 año
  `fecha_auditoria_2`     DATE,                        -- emision + 2 años
  `estado`                ENUM('vigente','por_vencer','vencido','suspendido','en_proceso') NOT NULL DEFAULT 'en_proceso',
  `observaciones`         TEXT,
  `creado_por`            INT UNSIGNED NOT NULL,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cert_cliente` (`cliente_id`),
  KEY `idx_cert_vencimiento` (`fecha_vencimiento`),
  CONSTRAINT `fk_cert_cliente` FOREIGN KEY (`cliente_id`)            REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_cert_norma`   FOREIGN KEY (`norma_iso_id`)          REFERENCES `normas_iso` (`id`),
  CONSTRAINT `fk_cert_casa`    FOREIGN KEY (`casa_certificadora_id`) REFERENCES `casas_certificadoras` (`id`),
  CONSTRAINT `fk_cert_usuario` FOREIGN KEY (`creado_por`)            REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  AUDITORÍAS DE SEGUIMIENTO
-- ============================================================

CREATE TABLE `auditorias` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `certificado_id`   INT UNSIGNED NOT NULL,
  `tipo`             ENUM('seguimiento_1','seguimiento_2','renovacion','extraordinaria') NOT NULL,
  `fecha_programada` DATE NOT NULL,
  `fecha_realizada`  DATE,
  `resultado`        ENUM('aprobado','observado','no_aprobado','pendiente') NOT NULL DEFAULT 'pendiente',
  `auditor`          VARCHAR(150),
  `informe_archivo`  VARCHAR(500),
  `observaciones`    TEXT,
  `registrado_por`   INT UNSIGNED NOT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aud_certificado` (`certificado_id`),
  CONSTRAINT `fk_aud_certificado` FOREIGN KEY (`certificado_id`) REFERENCES `certificados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aud_usuario`     FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  ENVÍOS
-- ============================================================

CREATE TABLE `envios` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente_id`        INT UNSIGNED NOT NULL,
  `certificado_id`    INT UNSIGNED NOT NULL,
  `agencia_envio_id`  INT UNSIGNED NOT NULL,
  `estado_envio_id`   INT UNSIGNED NOT NULL,
  `fecha_envio`       DATE,
  `fecha_recibido`    DATE,
  `codigo_envio`      VARCHAR(100),
  `clave_envio`       VARCHAR(100),
  `notas_entrega`     TEXT,
  `registrado_por`    INT UNSIGNED NOT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_env_cliente` (`cliente_id`),
  CONSTRAINT `fk_env_cliente`    FOREIGN KEY (`cliente_id`)       REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_env_certificado` FOREIGN KEY (`certificado_id`) REFERENCES `certificados` (`id`),
  CONSTRAINT `fk_env_agencia`    FOREIGN KEY (`agencia_envio_id`) REFERENCES `agencias_envio` (`id`),
  CONSTRAINT `fk_env_estado`     FOREIGN KEY (`estado_envio_id`)  REFERENCES `estados_envio` (`id`),
  CONSTRAINT `fk_env_usuario`    FOREIGN KEY (`registrado_por`)   REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  NOTIFICACIONES
-- ============================================================

CREATE TABLE `notificaciones` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT UNSIGNED NOT NULL,
  `tipo`        ENUM('cert_por_vencer','cert_vencido','auditoria_proxima','envio_sin_confirmar','cotizacion_vencida') NOT NULL,
  `titulo`      VARCHAR(200) NOT NULL,
  `mensaje`     TEXT NOT NULL,
  `url_accion`  VARCHAR(300),
  `leida`       TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_noti_usuario` (`usuario_id`, `leida`),
  CONSTRAINT `fk_noti_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  LOG DE ACTIVIDAD (trazabilidad)
-- ============================================================

CREATE TABLE `log_actividad` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT UNSIGNED,
  `modulo`      VARCHAR(50) NOT NULL,
  `accion`      VARCHAR(100) NOT NULL,
  `descripcion` TEXT,
  `ip`          VARCHAR(45),
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_usuario` (`usuario_id`),
  KEY `idx_log_modulo`  (`modulo`),
  CONSTRAINT `fk_log_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  SECUENCIA PARA CÓDIGOS AUTOMÁTICOS
-- ============================================================

CREATE TABLE `secuencias` (
  `tipo`    VARCHAR(30) NOT NULL,
  `año`     YEAR NOT NULL,
  `ultimo`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`tipo`, `año`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  DATOS INICIALES
-- ============================================================

-- Usuario administrador por defecto (password: Admin2025*)
INSERT INTO `usuarios` (`nombre`, `apellido`, `email`, `password_hash`, `rol`) VALUES
('Administrador', 'GGI', 'admin@groupglobaliso.com',
 '$2y$12$placeholder_hash_cambiar_en_produccion', 'gerente_general');

-- Normas ISO más comunes
INSERT INTO `normas_iso` (`codigo`, `nombre`, `descripcion`) VALUES
('ISO 9001:2015',   'Sistemas de Gestión de la Calidad',                     'Requisitos para un sistema de gestión de la calidad'),
('ISO 14001:2015',  'Sistemas de Gestión Ambiental',                         'Requisitos para un sistema de gestión ambiental'),
('ISO 45001:2018',  'Sistemas de Gestión de la Seguridad y Salud en el Trabajo', 'Requisitos para un sistema de SST'),
('ISO 22000:2018',  'Sistemas de Gestión de la Inocuidad de los Alimentos',  'Gestión de la seguridad alimentaria'),
('ISO 27001:2022',  'Sistemas de Gestión de la Seguridad de la Información', 'Gestión de seguridad de la información'),
('ISO 37001:2016',  'Sistemas de Gestión Antisoborno',                       'Gestión antisoborno'),
('ISO 50001:2018',  'Sistemas de Gestión de la Energía',                     'Gestión eficiente de la energía');

-- Casas certificadoras con las que trabaja GGI
INSERT INTO `casas_certificadoras` (`nombre`, `pais`) VALUES
('QRO',      'Perú'),
('HAWK EYE', 'Perú'),
('QFS',      'Perú'),
('UKS',      'Perú');

-- Agencias de envío frecuentes en Perú
INSERT INTO `agencias_envio` (`nombre`, `telefono`, `web`) VALUES
('Olva Courier',   '(01) 517-1717', 'https://www.olvacourier.com'),
('Shalom',         '(01) 610-0000', 'https://www.shalomempresarial.com'),
('Serpost',        '(01) 511-5000', 'https://www.serpost.com.pe'),
('DHL Express',    '(01) 517-2800', 'https://www.dhl.com/pe'),
('InDriver Envíos','',              '');

-- Estados de envío con colores para badges
INSERT INTO `estados_envio` (`nombre`, `descripcion`, `color_hex`, `orden`) VALUES
('Preparando',      'El envío está siendo preparado',         '#6B7280', 1),
('Despachado',      'El paquete fue entregado a la agencia',  '#3B82F6', 2),
('En tránsito',     'El paquete está en camino',              '#F59E0B', 3),
('En distribución', 'En reparto local en destino',            '#8B5CF6', 4),
('Entregado',       'Confirmado recibido por el cliente',     '#10B981', 5),
('Devuelto',        'El paquete fue devuelto',                '#EF4444', 6);

-- Secuencias iniciales para el año actual
INSERT INTO `secuencias` (`tipo`, `año`, `ultimo`) VALUES
('CLIENTE', YEAR(NOW()), 0),
('COTIZACION', YEAR(NOW()), 0);

-- ============================================================
--  TRIGGER: actualizar estado de certificados automáticamente
-- ============================================================

DELIMITER //

CREATE TRIGGER `trg_estado_certificado`
BEFORE INSERT ON `certificados`
FOR EACH ROW
BEGIN
  IF NEW.fecha_vencimiento < CURDATE() THEN
    SET NEW.estado = 'vencido';
  ELSEIF NEW.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN
    SET NEW.estado = 'por_vencer';
  ELSE
    SET NEW.estado = 'vigente';
  END IF;

  -- Auto-calcular fechas de auditoría si no se proveen
  IF NEW.fecha_auditoria_1 IS NULL THEN
    SET NEW.fecha_auditoria_1 = DATE_ADD(NEW.fecha_emision, INTERVAL 1 YEAR);
  END IF;
  IF NEW.fecha_auditoria_2 IS NULL THEN
    SET NEW.fecha_auditoria_2 = DATE_ADD(NEW.fecha_emision, INTERVAL 2 YEAR);
  END IF;
END//

CREATE TRIGGER `trg_estado_certificado_update`
BEFORE UPDATE ON `certificados`
FOR EACH ROW
BEGIN
  IF NEW.fecha_vencimiento < CURDATE() THEN
    SET NEW.estado = 'vencido';
  ELSEIF NEW.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN
    SET NEW.estado = 'por_vencer';
  END IF;
END//

-- TRIGGER: generar código interno de cliente automáticamente
CREATE TRIGGER `trg_codigo_cliente`
BEFORE INSERT ON `clientes`
FOR EACH ROW
BEGIN
  DECLARE v_ultimo INT;
  DECLARE v_año YEAR;
  SET v_año = YEAR(NOW());

  UPDATE `secuencias` SET `ultimo` = `ultimo` + 1
  WHERE `tipo` = 'CLIENTE' AND `año` = v_año;

  SELECT `ultimo` INTO v_ultimo
  FROM `secuencias`
  WHERE `tipo` = 'CLIENTE' AND `año` = v_año;

  SET NEW.codigo_interno = CONCAT('GGI-', v_año, '-', LPAD(v_ultimo, 3, '0'));
END//

DELIMITER ;

-- ============================================================
--  VISTAS ÚTILES
-- ============================================================

-- Vista: certificados próximos a vencer (90 días)
CREATE VIEW `v_certificados_por_vencer` AS
SELECT
  c.id,
  cl.codigo_interno,
  cl.razon_social,
  n.codigo AS norma,
  cc.nombre AS casa_certificadora,
  c.numero_certificado,
  c.fecha_vencimiento,
  DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_restantes,
  c.estado
FROM `certificados` c
JOIN `clientes` cl ON cl.id = c.cliente_id
JOIN `normas_iso` n ON n.id = c.norma_iso_id
JOIN `casas_certificadoras` cc ON cc.id = c.casa_certificadora_id
WHERE c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
ORDER BY c.fecha_vencimiento ASC;

-- Vista: auditorías próximas (60 días)
CREATE VIEW `v_auditorias_proximas` AS
SELECT
  a.id,
  cl.codigo_interno,
  cl.razon_social,
  n.codigo AS norma,
  a.tipo,
  a.fecha_programada,
  DATEDIFF(a.fecha_programada, CURDATE()) AS dias_restantes,
  a.resultado
FROM `auditorias` a
JOIN `certificados` c ON c.id = a.certificado_id
JOIN `clientes` cl ON cl.id = c.cliente_id
JOIN `normas_iso` n ON n.id = c.norma_iso_id
WHERE a.fecha_programada BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
  AND a.resultado = 'pendiente'
ORDER BY a.fecha_programada ASC;

-- Vista: envíos sin confirmar recepción
CREATE VIEW `v_envios_pendientes` AS
SELECT
  e.id,
  cl.codigo_interno,
  cl.razon_social,
  ag.nombre AS agencia,
  es.nombre AS estado,
  e.fecha_envio,
  e.codigo_envio,
  DATEDIFF(CURDATE(), e.fecha_envio) AS dias_en_transito
FROM `envios` e
JOIN `clientes` cl ON cl.id = e.cliente_id
JOIN `agencias_envio` ag ON ag.id = e.agencia_envio_id
JOIN `estados_envio` es ON es.id = e.estado_envio_id
WHERE e.fecha_recibido IS NULL
  AND e.fecha_envio IS NOT NULL
ORDER BY e.fecha_envio ASC;

-- ============================================================
--  FIN DEL SCRIPT
-- ============================================================
