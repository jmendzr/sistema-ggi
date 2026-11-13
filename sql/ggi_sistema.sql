-- ============================================================
--  GGI SISTEMA — Esquema de base de datos (estructura real)
--  Group Global ISO S.A.C.
--  Generado desde producción el 2026-11-13. 63 tablas + rutinas + triggers.
--  Importar sobre una base ya creada (sin CREATE DATABASE/USE).
--  Los datos de configuración van aparte (catálogos).
-- ============================================================

/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.19-MariaDB, for Linux (x86_64)
--
-- Host: localhost    Database: groupglo_sistema
-- ------------------------------------------------------
-- Server version	10.11.19-MariaDB-cll-lve

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `acta_alcance_cambios`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acta_alcance_cambios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `documento_id` int(10) unsigned NOT NULL,
  `cliente_id` int(10) unsigned NOT NULL,
  `cotizacion_id` int(10) unsigned DEFAULT NULL,
  `cambios_notas` longtext NOT NULL,
  `agregado_por` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_doc_id` (`documento_id`),
  KEY `idx_cliente_id` (`cliente_id`),
  KEY `fk_aac_usuario` (`agregado_por`),
  CONSTRAINT `fk_aac_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aac_documento` FOREIGN KEY (`documento_id`) REFERENCES `documentos_cliente` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aac_usuario` FOREIGN KEY (`agregado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de cambios/notas agregadas al Acta de Alcance';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `agencias_envio`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agencias_envio` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `web` varchar(150) DEFAULT NULL,
  `sede_modo` varchar(10) NOT NULL DEFAULT 'manual',
  `es_domicilio` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alertas_email`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `alertas_email` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `certificado_id` int(10) unsigned NOT NULL,
  `tipo_alerta` enum('90_dias','60_dias','30_dias') NOT NULL,
  `email_destino` varchar(150) NOT NULL,
  `enviado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alerta` (`certificado_id`,`tipo_alerta`),
  KEY `idx_cert` (`certificado_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `anuncios_vistos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `anuncios_vistos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned NOT NULL,
  `ann_hash` char(32) NOT NULL COMMENT 'md5(texto+tipo+version) del anuncio',
  `dismissed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuario_hash` (`usuario_id`,`ann_hash`),
  KEY `idx_av_usuario` (`usuario_id`),
  CONSTRAINT `fk_av_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro por usuario de los anuncios que ya visualizÃ³/cerrÃ³';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `auditorias`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auditorias` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `certificado_id` int(10) unsigned NOT NULL,
  `casa_certificadora_id` int(10) unsigned DEFAULT NULL,
  `auditor_externo` varchar(200) DEFAULT NULL,
  `tipo` enum('seguimiento_1','seguimiento_2','renovacion','extraordinaria') NOT NULL,
  `fecha_programada` date NOT NULL,
  `fecha_realizada` date DEFAULT NULL,
  `fecha_reprogramada` date DEFAULT NULL,
  `resultado` enum('aprobado','observado','no_aprobado','pendiente') NOT NULL DEFAULT 'pendiente',
  `informe_archivo` varchar(500) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aud_certificado` (`certificado_id`),
  KEY `fk_aud_usuario` (`registrado_por`),
  KEY `idx_aud_deleted_at` (`deleted_at`),
  KEY `idx_aud_deleted_resultado` (`deleted_at`,`resultado`),
  KEY `idx_aud_deleted_tipo` (`deleted_at`,`tipo`),
  CONSTRAINT `fk_aud_certificado` FOREIGN KEY (`certificado_id`) REFERENCES `certificados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aud_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=437 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bonos_config`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bonos_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `norma_iso_id` int(11) DEFAULT NULL COMMENT 'NULL = bono por defecto para cualquier norma',
  `monto_bono` decimal(10,2) NOT NULL DEFAULT 0.00,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_norma_iso` (`norma_iso_id`)
) ENGINE=InnoDB AUTO_INCREMENT=95 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bonos_ejecutivos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bonos_ejecutivos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ejecutivo_id` int(11) NOT NULL,
  `cotizacion_id` int(11) NOT NULL,
  `norma_iso_id` int(11) NOT NULL,
  `monto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `estado` enum('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
  `fecha_pago` date DEFAULT NULL,
  `pagado_at` datetime DEFAULT NULL,
  `pagado_por` int(11) DEFAULT NULL,
  `planilla_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cot_norma` (`cotizacion_id`,`norma_iso_id`),
  KEY `idx_ejecutivo` (`ejecutivo_id`),
  KEY `idx_cotizacion` (`cotizacion_id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_bonos_ejec_estado_fecha` (`ejecutivo_id`,`estado`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=262 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bonos_informes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bonos_informes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ejecutivo_id` int(10) unsigned NOT NULL,
  `periodo` char(7) NOT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `num_bonos` int(10) unsigned NOT NULL DEFAULT 0,
  `generado_por` int(10) unsigned DEFAULT NULL,
  `generado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `veces` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ej_periodo` (`ejecutivo_id`,`periodo`),
  KEY `idx_periodo` (`periodo`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `casas_certificadoras`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `casas_certificadoras` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `pais` varchar(80) DEFAULT NULL,
  `contacto` varchar(150) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `certificados`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `certificados` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `cotizacion_id` int(10) unsigned DEFAULT NULL,
  `norma_iso_id` int(10) unsigned NOT NULL,
  `casa_certificadora_id` int(10) unsigned DEFAULT NULL,
  `numero_certificado` varchar(100) DEFAULT NULL,
  `alcance` text DEFAULT NULL,
  `fecha_emision` date DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `fecha_auditoria_1` date DEFAULT NULL,
  `fecha_auditoria_2` date DEFAULT NULL,
  `estado` enum('vigente','por_vencer','vencido','suspendido','en_proceso') NOT NULL DEFAULT 'en_proceso',
  `observaciones` text DEFAULT NULL,
  `certificado_archivo` varchar(500) DEFAULT NULL,
  `creado_por` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  `certificado_archivo_en` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cert_cliente` (`cliente_id`),
  KEY `idx_cert_vencimiento` (`fecha_vencimiento`),
  KEY `fk_cert_norma` (`norma_iso_id`),
  KEY `fk_cert_casa` (`casa_certificadora_id`),
  KEY `fk_cert_usuario` (`creado_por`),
  KEY `idx_cert_estado` (`estado`),
  KEY `idx_cert_cliente_norma` (`cliente_id`,`norma_iso_id`),
  KEY `idx_cert_deleted` (`deleted_at`),
  KEY `idx_cert_cotizacion` (`cotizacion_id`),
  CONSTRAINT `fk_cert_casa` FOREIGN KEY (`casa_certificadora_id`) REFERENCES `casas_certificadoras` (`id`),
  CONSTRAINT `fk_cert_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_cert_norma` FOREIGN KEY (`norma_iso_id`) REFERENCES `normas_iso` (`id`),
  CONSTRAINT `fk_cert_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=276 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`groupglo_sistema`@`localhost`*/ /*!50003 TRIGGER trg_estado_certificado
BEFORE INSERT ON certificados
FOR EACH ROW
BEGIN
  IF NEW.estado != 'suspendido' THEN
    IF NEW.fecha_vencimiento IS NULL THEN
      SET NEW.estado = 'en_proceso';
    ELSEIF NEW.fecha_vencimiento < CURDATE() THEN
      SET NEW.estado = 'vencido';
    ELSEIF NEW.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN
      SET NEW.estado = 'por_vencer';
    ELSE
      SET NEW.estado = 'vigente';
    END IF;
  END IF;
  IF NEW.fecha_auditoria_1 IS NULL AND NEW.fecha_emision IS NOT NULL THEN
    SET NEW.fecha_auditoria_1 = DATE_ADD(NEW.fecha_emision, INTERVAL 1 YEAR);
  END IF;
  IF NEW.fecha_auditoria_2 IS NULL AND NEW.fecha_emision IS NOT NULL THEN
    SET NEW.fecha_auditoria_2 = DATE_ADD(NEW.fecha_emision, INTERVAL 2 YEAR);
  END IF;
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`groupglo_sistema`@`localhost`*/ /*!50003 TRIGGER trg_estado_certificado_update
BEFORE UPDATE ON certificados
FOR EACH ROW
BEGIN
  IF NEW.estado != 'suspendido' THEN
    IF NEW.fecha_vencimiento IS NULL THEN
      SET NEW.estado = 'en_proceso';
    ELSEIF NEW.fecha_vencimiento < CURDATE() THEN
      SET NEW.estado = 'vencido';
    ELSEIF NEW.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN
      SET NEW.estado = 'por_vencer';
    ELSE
      SET NEW.estado = 'vigente';
    END IF;
  END IF;
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `cliente_isos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cliente_isos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `norma_iso_id` int(10) unsigned NOT NULL,
  `cantidad` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `fecha_adquisicion` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cliente_norma` (`cliente_id`,`norma_iso_id`),
  KEY `fk_ci_norma` (`norma_iso_id`),
  CONSTRAINT `fk_ci_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ci_norma` FOREIGN KEY (`norma_iso_id`) REFERENCES `normas_iso` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clientes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clientes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo_interno` varchar(20) NOT NULL,
  `ruc` char(11) NOT NULL,
  `razon_social` varchar(200) NOT NULL,
  `direccion` varchar(300) DEFAULT NULL,
  `ubigeo` varchar(20) DEFAULT NULL,
  `departamento` varchar(80) DEFAULT NULL,
  `provincia` varchar(80) DEFAULT NULL,
  `distrito` varchar(80) DEFAULT NULL,
  `contacto_nombre` varchar(100) DEFAULT NULL,
  `contacto_apellido` varchar(100) DEFAULT NULL,
  `contacto_telefono` varchar(20) DEFAULT NULL,
  `horario_contacto` varchar(120) DEFAULT NULL,
  `whatsapp_opt_in` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=acepta mensajes, 0=opt-out',
  `contacto_email` varchar(150) DEFAULT NULL,
  `contacto_cargo` varchar(100) DEFAULT NULL,
  `num_trabajadores` int(10) unsigned DEFAULT NULL,
  `fecha_contrato` date DEFAULT NULL,
  `estado` enum('activo','inactivo','prospecto') NOT NULL DEFAULT 'activo',
  `meta_venta_reportada_at` datetime DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `revision_gerente` enum('pendiente','revisado') NOT NULL DEFAULT 'pendiente',
  `portal_token` varchar(64) DEFAULT NULL,
  `portal_activo` tinyint(1) NOT NULL DEFAULT 0,
  `creado_por` int(10) unsigned NOT NULL,
  `ejecutivo_id` int(10) unsigned DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL COMMENT 'Ejecutivo que captó originalmente la empresa — inmutable tras creación',
  `responsible_user_id` int(10) unsigned DEFAULT NULL COMMENT 'Ejecutivo actualmente responsable — cambia al aceptar propuesta de otro ejecutivo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo_interno` (`codigo_interno`),
  UNIQUE KEY `ruc` (`ruc`),
  KEY `idx_razon_social` (`razon_social`),
  KEY `fk_clientes_usuario` (`creado_por`),
  KEY `idx_portal_token` (`portal_token`),
  KEY `idx_cliente_ejecutivo` (`ejecutivo_id`),
  KEY `idx_cli_created` (`created_at`),
  KEY `idx_cli_estado` (`estado`),
  KEY `idx_cli_responsible` (`responsible_user_id`),
  KEY `idx_cli_created_by` (`created_by_user_id`),
  KEY `idx_cli_deleted` (`deleted_at`),
  KEY `idx_cli_deleted_estado` (`deleted_at`,`estado`),
  KEY `idx_cli_revision` (`revision_gerente`),
  CONSTRAINT `fk_cliente_ejecutivo` FOREIGN KEY (`ejecutivo_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_clientes_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_clientes_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_clientes_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=540 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`groupglo_sistema`@`localhost`*/ /*!50003 TRIGGER `trg_codigo_cliente` BEFORE INSERT ON `clientes` FOR EACH ROW BEGIN
  DECLARE v_ultimo INT;
  DECLARE v_año YEAR;
  SET v_año = YEAR(NOW());

  UPDATE `secuencias` SET `ultimo` = `ultimo` + 1
  WHERE `tipo` = 'CLIENTE' AND `año` = v_año;

  SELECT `ultimo` INTO v_ultimo
  FROM `secuencias`
  WHERE `tipo` = 'CLIENTE' AND `año` = v_año;

  SET NEW.codigo_interno = CONCAT('GGI-', v_año, '-', LPAD(v_ultimo, 3, '0'));
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`groupglo_sistema`@`localhost`*/ /*!50003 TRIGGER trg_clientes_portal_token BEFORE INSERT ON clientes
            FOR EACH ROW
            BEGIN
              IF NEW.portal_token IS NULL OR NEW.portal_token = "" THEN
                SET NEW.portal_token = SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256);
                SET NEW.portal_activo = 1;
              END IF;
            END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `comunicaciones_cliente`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `comunicaciones_cliente` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `mensaje` text NOT NULL,
  `tipo` enum('nota','alerta','seguimiento','llamada','reunion') NOT NULL DEFAULT 'nota',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_com_cliente` (`cliente_id`),
  KEY `fk_com_usuario` (`usuario_id`),
  CONSTRAINT `fk_com_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_com_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `configuracion`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion` (
  `clave` varchar(100) NOT NULL,
  `valor` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cotizacion_archivos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cotizacion_archivos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cotizacion_id` int(10) unsigned NOT NULL,
  `tipo` varchar(50) NOT NULL DEFAULT 'firmada',
  `nombre_archivo` varchar(255) NOT NULL,
  `ruta_archivo` varchar(500) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `tamano_bytes` int(10) unsigned DEFAULT NULL,
  `version` tinyint(3) unsigned DEFAULT 1,
  `subido_por` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cot_tipo` (`cotizacion_id`,`tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cotizacion_normas`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cotizacion_normas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cotizacion_id` int(10) unsigned NOT NULL,
  `norma_iso_id` int(10) unsigned NOT NULL,
  `precio` decimal(10,2) DEFAULT NULL,
  `descuento` int(11) NOT NULL DEFAULT 0,
  `casa_certificadora_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cotizacion_id` (`cotizacion_id`),
  KEY `norma_iso_id` (`norma_iso_id`),
  CONSTRAINT `cotizacion_normas_ibfk_1` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cotizacion_normas_ibfk_2` FOREIGN KEY (`norma_iso_id`) REFERENCES `normas_iso` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2033 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cotizacion_seguimientos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cotizacion_seguimientos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cotizacion_id` int(10) unsigned NOT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `tipo` enum('llamada','email','reunion','whatsapp','otro') NOT NULL DEFAULT 'llamada',
  `notas` text DEFAULT NULL,
  `resultado` enum('sin_respuesta','interesado','pidio_tiempo','rechazado','aceptado') NOT NULL DEFAULT 'sin_respuesta',
  `proxima_accion` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cotizacion` (`cotizacion_id`),
  KEY `idx_usuario` (`usuario_id`),
  CONSTRAINT `fk_seg_cotizacion` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_seg_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cotizaciones`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cotizaciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(20) NOT NULL,
  `tipo_empresa` enum('existente','nueva') NOT NULL DEFAULT 'nueva',
  `empresa_ruc` varchar(20) DEFAULT NULL,
  `empresa_nombre` varchar(255) DEFAULT NULL,
  `empresa_direccion` varchar(500) DEFAULT NULL,
  `contacto_nombre` varchar(100) DEFAULT NULL,
  `contacto_telefono` varchar(20) DEFAULT NULL,
  `contacto_email` varchar(150) DEFAULT NULL,
  `codigo` varchar(20) NOT NULL,
  `cliente_id` int(10) unsigned DEFAULT NULL,
  `estado` enum('borrador','enviada','aceptada','rechazada','vencida','desistida') NOT NULL DEFAULT 'borrador',
  `fecha_aceptacion` datetime DEFAULT NULL,
  `monto_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `descuento` decimal(10,2) DEFAULT 0.00,
  `campana_descuento` varchar(100) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT 0.00,
  `igv` decimal(10,2) DEFAULT 0.00,
  `auditoria_precio` decimal(10,2) DEFAULT NULL,
  `auditoria_precio2` decimal(10,2) DEFAULT NULL,
  `auditoria_mes` tinyint(2) DEFAULT NULL,
  `auditoria_anio` smallint(4) DEFAULT NULL,
  `moneda` enum('PEN','USD') NOT NULL DEFAULT 'PEN',
  `fecha_emision` date NOT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `archivo_pdf` varchar(500) DEFAULT NULL,
  `motivo_rechazo` text DEFAULT NULL,
  `motivo_desistimiento` text DEFAULT NULL,
  `desistida_at` datetime DEFAULT NULL,
  `desistida_by` int(10) unsigned DEFAULT NULL,
  `creado_por` int(10) unsigned NOT NULL,
  `ejecutivo_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  `empresa_dep` varchar(100) DEFAULT NULL,
  `empresa_prov` varchar(100) DEFAULT NULL,
  `empresa_dist` varchar(100) DEFAULT NULL,
  `empresa_ubigeo` varchar(30) DEFAULT NULL,
  `contacto_apellido` varchar(150) DEFAULT NULL,
  `num_trabajadores` int(10) unsigned DEFAULT NULL,
  `contacto_cargo` varchar(150) DEFAULT NULL,
  `incluye_igv` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_requerida_certificados` date DEFAULT NULL,
  `alerta_cert_at` datetime DEFAULT NULL,
  `aviso_vence_3d_at` datetime DEFAULT NULL,
  `aviso_vence_1d_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  UNIQUE KEY `uq_numero` (`numero`),
  KEY `fk_cot_cliente` (`cliente_id`),
  KEY `fk_cot_usuario` (`creado_por`),
  KEY `idx_cot_ejecutivo` (`ejecutivo_id`),
  KEY `idx_cot_estado` (`estado`),
  KEY `idx_cot_created` (`created_at`),
  KEY `idx_cot_fecha_emision` (`fecha_emision`),
  KEY `idx_cot_vencimiento` (`fecha_vencimiento`),
  KEY `idx_cot_estado_vcto` (`estado`,`fecha_vencimiento`),
  KEY `idx_cot_ejec_estado` (`ejecutivo_id`,`estado`),
  KEY `idx_cot_deleted` (`deleted_at`),
  KEY `idx_cot_deleted_estado` (`deleted_at`,`estado`),
  CONSTRAINT `fk_cot_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_cot_ejecutivo` FOREIGN KEY (`ejecutivo_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cot_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=656 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = latin1 */ ;
/*!50003 SET character_set_results = latin1 */ ;
/*!50003 SET collation_connection  = latin1_swedish_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`groupglo_sistema`@`localhost`*/ /*!50003 TRIGGER trg_numero_cotizacion
BEFORE INSERT ON cotizaciones
FOR EACH ROW
BEGIN
  DECLARE v_ultimo INT;
  DECLARE v_anio YEAR;
  IF NEW.numero IS NULL OR NEW.numero = '' THEN
    SET v_anio = YEAR(NOW());
    INSERT INTO secuencias (tipo, `año`, ultimo)
    VALUES ('COT', v_anio, 0)
    ON DUPLICATE KEY UPDATE tipo = tipo;
    UPDATE secuencias SET ultimo = ultimo + 1
    WHERE tipo = 'COT' AND `año` = v_anio;
    SELECT ultimo INTO v_ultimo
    FROM secuencias
    WHERE tipo = 'COT' AND `año` = v_anio;
    SET NEW.numero = CONCAT('COT-', v_anio, '-', LPAD(v_ultimo, 7, '0'));
    SET NEW.codigo = NEW.numero;
  END IF;
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `cotizaciones_historial`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cotizaciones_historial` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cotizacion_id` int(11) NOT NULL,
  `version_num` smallint(6) NOT NULL DEFAULT 1,
  `editado_por` int(11) DEFAULT NULL,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Fila completa de cotizaciones antes del cambio' CHECK (json_valid(`snapshot`)),
  `normas_snap` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Filas de cotizacion_normas antes del cambio' CHECK (json_valid(`normas_snap`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ch_cot` (`cotizacion_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documentos_cliente`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentos_cliente` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `cotizacion_id` int(10) unsigned DEFAULT NULL,
  `tipo_documento` enum('ficha_ruc','copia_literal','acta_alcance','voucher_inicial','voucher_final','logo','cotizacion_firmada','attendance_sheet','application_form') NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `ruta_archivo` varchar(500) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `tamano_bytes` int(10) unsigned DEFAULT NULL,
  `alcance_texto` text DEFAULT NULL,
  `confirmada` tinyint(1) NOT NULL DEFAULT 0,
  `confirmada_por` int(10) unsigned DEFAULT NULL,
  `confirmada_at` timestamp NULL DEFAULT NULL,
  `version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `es_vigente` tinyint(1) NOT NULL DEFAULT 1,
  `subido_por` int(10) unsigned NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado_acta` enum('vigente','pendiente_cambios','confirmada') DEFAULT 'vigente' COMMENT 'vigente=lista para aprobar, pendiente_cambios=ejecutivo solicitó cambios, confirmada=aprobada',
  PRIMARY KEY (`id`),
  KEY `idx_doc_cliente` (`cliente_id`,`tipo_documento`,`es_vigente`),
  KEY `fk_doc_usuario` (`subido_por`),
  KEY `idx_doc_cotizacion` (`cotizacion_id`),
  CONSTRAINT `fk_doc_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_doc_usuario` FOREIGN KEY (`subido_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=398 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `envios`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `envios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `cotizacion_id` int(10) unsigned DEFAULT NULL,
  `agencia_envio_id` int(10) unsigned DEFAULT NULL,
  `sede_registro` varchar(200) DEFAULT NULL,
  `tienda_destino` varchar(500) DEFAULT NULL,
  `estado_envio_id` int(10) unsigned NOT NULL,
  `fecha_envio` date DEFAULT NULL,
  `fecha_recibido` date DEFAULT NULL,
  `codigo_envio` varchar(100) DEFAULT NULL,
  `clave_envio` varchar(100) DEFAULT NULL,
  `notas_entrega` text DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  `direccion_destino` varchar(500) DEFAULT NULL,
  `destinatario` varchar(255) DEFAULT NULL,
  `destinatario_dni` varchar(20) DEFAULT NULL,
  `numero_orden` varchar(100) DEFAULT NULL,
  `codigo_recojo` varchar(100) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `sin_datos_envio` tinyint(1) NOT NULL DEFAULT 0,
  `motivo_no_envio` varchar(30) DEFAULT NULL,
  `motivo_no_envio_detalle` varchar(500) DEFAULT NULL,
  `sugerido_no_envio_at` datetime DEFAULT NULL,
  `recordatorio_datos_at` datetime DEFAULT NULL,
  `recordatorio_datos_n` tinyint(4) NOT NULL DEFAULT 0,
  `comprobante_archivo` varchar(500) DEFAULT NULL,
  `ejecutivo_id` int(10) unsigned DEFAULT NULL,
  `agente_id` int(10) unsigned DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `referencia_direccion` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_env_cliente` (`cliente_id`),
  KEY `fk_env_agencia` (`agencia_envio_id`),
  KEY `fk_env_estado` (`estado_envio_id`),
  KEY `fk_env_usuario` (`registrado_por`),
  KEY `fk_envio_ejecutivo` (`ejecutivo_id`),
  KEY `fk_envio_agente` (`agente_id`),
  KEY `idx_env_deleted` (`deleted_at`),
  KEY `idx_env_cotizacion` (`cotizacion_id`),
  CONSTRAINT `fk_env_agencia` FOREIGN KEY (`agencia_envio_id`) REFERENCES `agencias_envio` (`id`),
  CONSTRAINT `fk_env_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_env_estado` FOREIGN KEY (`estado_envio_id`) REFERENCES `estados_envio` (`id`),
  CONSTRAINT `fk_env_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_envio_agente` FOREIGN KEY (`agente_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_envio_ejecutivo` FOREIGN KEY (`ejecutivo_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=109 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `envios_certificados`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `envios_certificados` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `envio_id` int(10) unsigned NOT NULL,
  `certificado_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `envio_id` (`envio_id`),
  KEY `certificado_id` (`certificado_id`),
  CONSTRAINT `envios_certificados_ibfk_1` FOREIGN KEY (`envio_id`) REFERENCES `envios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `envios_certificados_ibfk_2` FOREIGN KEY (`certificado_id`) REFERENCES `certificados` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=558 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `estados_envio`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `estados_envio` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `descripcion` varchar(200) DEFAULT NULL,
  `color_hex` varchar(7) DEFAULT '#6B7280',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` tinyint(3) unsigned DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `facturas_cliente`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_cliente` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `cotizacion_id` int(10) unsigned DEFAULT NULL,
  `origen` enum('manual','alegra') NOT NULL DEFAULT 'manual',
  `numero_factura` varchar(50) DEFAULT NULL,
  `alegra_id` varchar(30) DEFAULT NULL,
  `tipo_factura` enum('total','anticipo','saldo') DEFAULT NULL,
  `operation_type` varchar(40) DEFAULT NULL,
  `detraccion_modo` enum('cliente','auto','ninguna') DEFAULT NULL,
  `monto_base` decimal(10,2) DEFAULT NULL,
  `archivo_path` varchar(500) DEFAULT NULL,
  `archivo_nombre` varchar(255) DEFAULT NULL,
  `wa_enviado` tinyint(1) NOT NULL DEFAULT 0,
  `wa_enviado_at` timestamp NULL DEFAULT NULL,
  `subido_por` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `monto_igv` decimal(10,2) DEFAULT NULL,
  `monto_total` decimal(10,2) DEFAULT NULL,
  `detraccion_monto` decimal(10,2) DEFAULT NULL,
  `estado_timbrado` varchar(30) DEFAULT NULL,
  `alegra_resp` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fc_cliente` (`cliente_id`),
  KEY `idx_fc_cotizacion` (`cotizacion_id`)
) ENGINE=InnoDB AUTO_INCREMENT=105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `feriados`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `feriados` (
  `fecha` date NOT NULL,
  `nombre` varchar(120) NOT NULL,
  PRIMARY KEY (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `historial_responsables_cliente`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `historial_responsables_cliente` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `responsable_anterior` int(10) unsigned DEFAULT NULL,
  `responsable_nuevo` int(10) unsigned DEFAULT NULL,
  `motivo` enum('creacion','aceptacion_propuesta','reasignacion_manual','migracion') NOT NULL DEFAULT 'creacion',
  `cotizacion_id` int(10) unsigned DEFAULT NULL,
  `registrado_por` int(10) unsigned DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hrc_cliente` (`cliente_id`),
  KEY `idx_hrc_nuevo` (`responsable_nuevo`),
  KEY `idx_hrc_fecha` (`created_at`),
  KEY `fk_hrc_anterior` (`responsable_anterior`),
  KEY `fk_hrc_cotizacion` (`cotizacion_id`),
  KEY `fk_hrc_registrado_por` (`registrado_por`),
  CONSTRAINT `fk_hrc_anterior` FOREIGN KEY (`responsable_anterior`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hrc_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hrc_cotizacion` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hrc_nuevo` FOREIGN KEY (`responsable_nuevo`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hrc_registrado_por` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=278 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historial de cambios de ejecutivo responsable por empresa';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `llamadas_programadas`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `llamadas_programadas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `cotizacion_id` int(10) unsigned DEFAULT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `fecha_hora` datetime NOT NULL,
  `aviso_min` tinyint(3) unsigned NOT NULL DEFAULT 15,
  `nota` varchar(500) DEFAULT NULL,
  `estado` enum('pendiente','notificada','realizada','cancelada') NOT NULL DEFAULT 'pendiente',
  `aviso_previo_at` datetime DEFAULT NULL,
  `aviso_hora_at` datetime DEFAULT NULL,
  `nota_cierre` varchar(500) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_llam_usuario_estado` (`usuario_id`,`estado`),
  KEY `idx_llam_fecha` (`fecha_hora`),
  KEY `idx_llam_cliente` (`cliente_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `log_actividad`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `log_actividad` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `modulo` varchar(50) NOT NULL,
  `accion` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_log_usuario` (`usuario_id`),
  KEY `idx_log_modulo` (`modulo`),
  KEY `idx_log_created` (`created_at`),
  KEY `idx_log_modulo_accion_ts` (`modulo`,`accion`,`created_at`),
  CONSTRAINT `fk_log_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5451 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `metas_ejecutivos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `metas_ejecutivos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ejecutivo_id` int(11) NOT NULL,
  `mes` tinyint(4) NOT NULL,
  `año` smallint(6) NOT NULL,
  `meta_monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `meta_cotizaciones` int(11) NOT NULL DEFAULT 0,
  `creado_por` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ejec_mes_año` (`ejecutivo_id`,`mes`,`año`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `normas_iso`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `normas_iso` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `meses_vigencia` smallint(5) unsigned NOT NULL DEFAULT 36 COMMENT 'Vigencia del certificado en meses (36=3 años ISO, 12=1 año BPL)',
  `descripcion` text DEFAULT NULL,
  `precio_base` decimal(10,2) DEFAULT NULL,
  `auditoria_precio` decimal(10,2) DEFAULT NULL COMMENT 'Precio de auditorÃ­a de seguimiento por norma',
  `orden` tinyint(3) unsigned NOT NULL DEFAULT 99,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `descuento_maximo` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `requiere_acta` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notas_credito_cliente`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_credito_cliente` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `factura_id` int(10) unsigned NOT NULL,
  `cliente_id` int(10) unsigned NOT NULL,
  `cotizacion_id` int(10) unsigned DEFAULT NULL,
  `alegra_id` varchar(30) DEFAULT NULL,
  `numero` varchar(50) DEFAULT NULL,
  `tipo` varchar(40) NOT NULL,
  `motivo` varchar(500) NOT NULL,
  `monto_base` decimal(10,2) DEFAULT NULL,
  `monto_igv` decimal(10,2) DEFAULT NULL,
  `monto_total` decimal(10,2) DEFAULT NULL,
  `estado_timbrado` varchar(30) DEFAULT NULL,
  `alegra_resp` text DEFAULT NULL,
  `archivo_path` varchar(500) DEFAULT NULL,
  `archivo_nombre` varchar(255) DEFAULT NULL,
  `wa_enviado` tinyint(1) NOT NULL DEFAULT 0,
  `wa_enviado_at` timestamp NULL DEFAULT NULL,
  `creado_por` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nc_factura` (`factura_id`),
  KEY `idx_nc_cliente` (`cliente_id`),
  KEY `idx_nc_cot` (`cotizacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notificaciones`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notificaciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned NOT NULL,
  `tipo` varchar(100) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `mensaje` text NOT NULL,
  `url_accion` varchar(300) DEFAULT NULL,
  `leida` tinyint(1) NOT NULL DEFAULT 0,
  `priority` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `reference_id` int(10) unsigned DEFAULT NULL,
  `event_code` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_noti_usuario` (`usuario_id`,`leida`),
  KEY `idx_notif_leida` (`usuario_id`,`leida`),
  CONSTRAINT `fk_noti_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2777 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_events`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_events` (
  `code` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `url_pattern` varchar(300) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_code` varchar(100) NOT NULL,
  `triggered_by` int(10) unsigned DEFAULT NULL,
  `reference_id` int(10) unsigned DEFAULT NULL,
  `recipients` smallint(6) NOT NULL DEFAULT 0,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nh_event` (`event_code`),
  KEY `idx_nh_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=601 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_rules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_code` varchar(100) NOT NULL,
  `recipient_type` enum('ROLE','PROPOSAL_OWNER','COMPANY_EXECUTIVE','COMPANY_CONTACT','USER') NOT NULL DEFAULT 'ROLE',
  `role` varchar(60) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `priority` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `via_interna` tinyint(1) NOT NULL DEFAULT 1,
  `via_push` tinyint(1) NOT NULL DEFAULT 0,
  `via_whatsapp` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rule` (`event_code`,`recipient_type`,`role`),
  KEY `idx_event` (`event_code`),
  CONSTRAINT `fk_nr_event` FOREIGN KEY (`event_code`) REFERENCES `notification_events` (`code`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pagos_cliente`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pagos_cliente` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `cotizacion_id` int(10) unsigned NOT NULL,
  `tipo` enum('inicial','final') NOT NULL,
  `porcentaje` decimal(5,2) NOT NULL DEFAULT 50.00,
  `monto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `moneda` enum('PEN','USD') NOT NULL DEFAULT 'PEN',
  `fecha_pago_decl` date DEFAULT NULL COMMENT 'Fecha declarada por ejecutivo al subir voucher',
  `banco` varchar(100) DEFAULT NULL,
  `referencia` varchar(100) DEFAULT NULL COMMENT 'Nro. operacion bancaria',
  `voucher_path` varchar(500) DEFAULT NULL,
  `voucher_nombre` varchar(255) DEFAULT NULL,
  `estado` enum('pendiente_voucher','pendiente_validacion','validado','rechazado') NOT NULL DEFAULT 'pendiente_voucher',
  `subido_por` int(10) unsigned DEFAULT NULL,
  `fecha_subida` datetime DEFAULT NULL,
  `validado_por` int(10) unsigned DEFAULT NULL,
  `fecha_validacion` datetime DEFAULT NULL,
  `notas_validacion` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_cotizacion` (`cotizacion_id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_pagos_estado_fecha` (`estado`,`fecha_validacion`),
  KEY `idx_pago_cot_estado` (`cotizacion_id`,`estado`),
  KEY `idx_pagos_deleted_at` (`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=228 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `planilla`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `planilla` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned NOT NULL,
  `periodo` char(7) NOT NULL,
  `regimen` enum('planilla','rxh') NOT NULL DEFAULT 'planilla',
  `sueldo_base` decimal(10,2) NOT NULL DEFAULT 0.00,
  `comision` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonos` decimal(10,2) NOT NULL DEFAULT 0.00,
  `descuentos` decimal(10,2) NOT NULL DEFAULT 0.00,
  `neto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `estado` enum('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
  `metodo_pago` varchar(40) DEFAULT NULL,
  `fecha_pago` date DEFAULT NULL,
  `comprobante_path` varchar(255) DEFAULT NULL,
  `notas` varchar(300) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `pagado_por` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_usuario_periodo` (`usuario_id`,`periodo`),
  KEY `idx_periodo` (`periodo`),
  KEY `idx_estado` (`estado`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `planilla_config`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `planilla_config` (
  `usuario_id` int(10) unsigned NOT NULL,
  `regimen` enum('planilla','rxh') NOT NULL DEFAULT 'planilla',
  `monto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `comisiona` tinyint(1) NOT NULL DEFAULT 0,
  `incluir` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `banco` varchar(60) DEFAULT NULL,
  `num_cuenta` varchar(40) DEFAULT NULL,
  `cci` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `push_subscriptions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` varchar(512) NOT NULL,
  `auth` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ps_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rol_permisos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rol_permisos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rol` varchar(50) NOT NULL,
  `modulo` varchar(50) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rol_modulo` (`rol`,`modulo`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `nombre` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#1A3A8F',
  `orden` tinyint(3) unsigned NOT NULL DEFAULT 99,
  PRIMARY KEY (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `secuencias`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `secuencias` (
  `tipo` varchar(30) NOT NULL,
  `año` year(4) NOT NULL,
  `ultimo` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`tipo`,`año`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usuarios`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `apellido` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `salario_mensual` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fecha_cumpleanos` date DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `rol` varchar(50) NOT NULL DEFAULT 'ejecutivo_ventas',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_acceso` timestamp NULL DEFAULT NULL,
  `ultimo_ip` varchar(45) DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_exp` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ultima_visita_pagos` datetime DEFAULT NULL,
  `ultima_visita_propuestas` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_usuarios_username` (`username`),
  KEY `idx_reset_token` (`reset_token`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary table structure for view `v_auditorias_proximas`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_auditorias_proximas` AS SELECT
 NULL AS `id`,
 NULL AS `codigo_interno`,
 NULL AS `razon_social`,
 NULL AS `norma`,
 NULL AS `tipo`,
 NULL AS `fecha_programada`,
 NULL AS `dias_restantes`,
 NULL AS `resultado` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_certificados_por_vencer`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_certificados_por_vencer` AS SELECT
 NULL AS `id`,
 NULL AS `codigo_interno`,
 NULL AS `razon_social`,
 NULL AS `norma`,
 NULL AS `casa_certificadora`,
 NULL AS `numero_certificado`,
 NULL AS `fecha_vencimiento`,
 NULL AS `dias_restantes`,
 NULL AS `estado` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_envios_pendientes`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_envios_pendientes` AS SELECT
 NULL AS `id`,
 NULL AS `codigo_interno`,
 NULL AS `razon_social`,
 NULL AS `agencia`,
 NULL AS `estado`,
 NULL AS `fecha_envio`,
 NULL AS `codigo_envio`,
 NULL AS `dias_en_transito` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `wa_asignaciones_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_asignaciones_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversacion_id` int(10) unsigned NOT NULL,
  `accion` enum('tomar','soltar','reasignar','quitar') NOT NULL,
  `de_usuario` int(10) unsigned DEFAULT NULL,
  `a_usuario` int(10) unsigned DEFAULT NULL,
  `hecho_por` int(10) unsigned NOT NULL,
  `motivo` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversacion_id`,`id`),
  KEY `idx_quien` (`hecho_por`)
) ENGINE=InnoDB AUTO_INCREMENT=160 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_bot_botones`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_bot_botones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nodo_id` int(11) NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `label` varchar(30) NOT NULL,
  `destino_clave` varchar(60) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `k_nodo` (`nodo_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_bot_eventos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_bot_eventos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversacion_id` int(11) DEFAULT NULL,
  `evento` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `k_evt` (`evento`),
  KEY `k_conv` (`conversacion_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_bot_nodos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_bot_nodos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clave` varchar(60) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `tipo` enum('mensaje','menu','pedir_ruc','pedir_normas','condicion','ia','send_template','esperar','webhook','emit','derivar','fin') NOT NULL DEFAULT 'mensaje',
  `texto` text DEFAULT NULL,
  `es_inicio` tinyint(1) NOT NULL DEFAULT 0,
  `siguiente_clave` varchar(60) DEFAULT NULL,
  `fijar_normas` varchar(255) DEFAULT NULL,
  `etiqueta_nombre` varchar(60) DEFAULT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `pos_x` int(11) DEFAULT NULL,
  `pos_y` int(11) DEFAULT NULL,
  `config_json` text DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clave` (`clave`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_bot_reglas`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_bot_reglas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nodo_id` int(11) NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `campo` enum('ruc','normas','razon','texto') NOT NULL DEFAULT 'texto',
  `operador` enum('tiene','no_tiene','contiene','no_contiene') NOT NULL DEFAULT 'contiene',
  `valor` varchar(190) DEFAULT NULL,
  `destino_clave` varchar(60) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `k_nodo` (`nodo_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_bot_triggers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_bot_triggers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `palabras` text NOT NULL,
  `destino_clave` varchar(60) NOT NULL,
  `prioridad` int(11) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_campana_destinatarios`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_campana_destinatarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campana_id` int(10) unsigned NOT NULL,
  `cliente_id` int(10) unsigned DEFAULT NULL,
  `telefono` varchar(20) NOT NULL,
  `empresa` varchar(200) DEFAULT NULL,
  `params_json` text DEFAULT NULL,
  `estado` enum('pendiente','enviado','entregado','leido','fallido') NOT NULL DEFAULT 'pendiente',
  `wa_message_id` varchar(150) DEFAULT NULL,
  `error_msg` varchar(400) DEFAULT NULL,
  `intentos` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `enviado_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_camp_tel` (`campana_id`,`telefono`),
  KEY `idx_cola` (`campana_id`,`estado`,`id`),
  KEY `idx_waid` (`wa_message_id`)
) ENGINE=InnoDB AUTO_INCREMENT=151 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_conversacion_etiquetas`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_conversacion_etiquetas` (
  `conversacion_id` int(10) unsigned NOT NULL,
  `etiqueta_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`conversacion_id`,`etiqueta_id`),
  KEY `idx_wce_etq` (`etiqueta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_conversacion_notas`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_conversacion_notas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversacion_id` int(10) unsigned NOT NULL,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `nota` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wacn_conv` (`conversacion_id`),
  KEY `fk_wacn_user` (`usuario_id`),
  CONSTRAINT `fk_wacn_conv` FOREIGN KEY (`conversacion_id`) REFERENCES `wa_conversaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wacn_user` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_conversaciones`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_conversaciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `telefono` varchar(20) NOT NULL,
  `perfil_nombre` varchar(120) DEFAULT NULL,
  `phone_number_id` varchar(32) NOT NULL,
  `cliente_id` int(10) unsigned DEFAULT NULL,
  `asignado_a` int(10) unsigned DEFAULT NULL,
  `asignado_at` datetime DEFAULT NULL,
  `estado` enum('abierta','resuelta') NOT NULL DEFAULT 'abierta',
  `ventana_expira_at` datetime DEFAULT NULL,
  `ultimo_mensaje_at` datetime DEFAULT NULL,
  `ultimo_mensaje_preview` varchar(200) DEFAULT NULL,
  `no_leidos` int(10) unsigned NOT NULL DEFAULT 0,
  `sin_leer_manual` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fijada` tinyint(1) NOT NULL DEFAULT 0,
  `lead_caliente_at` datetime DEFAULT NULL,
  `auto_fuera_at` datetime DEFAULT NULL,
  `archivada` tinyint(1) NOT NULL DEFAULT 0,
  `silenciada` tinyint(1) NOT NULL DEFAULT 0,
  `bot_estado` varchar(20) DEFAULT NULL,
  `bot_nodo` varchar(60) DEFAULT NULL,
  `bot_ruc` varchar(11) DEFAULT NULL,
  `bot_certs` varchar(255) DEFAULT NULL,
  `bot_razon` varchar(200) DEFAULT NULL,
  `bot_intentos` tinyint(4) NOT NULL DEFAULT 0,
  `bot_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_conv` (`telefono`,`phone_number_id`),
  KEY `idx_wc_asignado` (`asignado_a`),
  KEY `idx_wc_cliente` (`cliente_id`),
  KEY `idx_wc_bandeja` (`estado`,`ultimo_mensaje_at`),
  KEY `idx_fijada` (`fijada`)
) ENGINE=InnoDB AUTO_INCREMENT=13741 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_etiquetas`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_etiquetas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'neutral',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_etq` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_llamadas`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_llamadas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `wa_call_id` varchar(150) DEFAULT NULL,
  `conversacion_id` int(10) unsigned DEFAULT NULL,
  `phone_number_id` varchar(32) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `direccion` varchar(20) DEFAULT NULL,
  `evento` varchar(30) DEFAULT NULL,
  `estado` varchar(30) DEFAULT NULL,
  `duracion_seg` int(11) DEFAULT NULL,
  `payload_json` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_call` (`wa_call_id`),
  KEY `idx_conv` (`conversacion_id`),
  KEY `idx_creada` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_mensajes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_mensajes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversacion_id` int(10) unsigned NOT NULL,
  `wa_message_id` varchar(150) DEFAULT NULL,
  `direccion` enum('in','out') NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'text',
  `reenviado` tinyint(1) NOT NULL DEFAULT 0,
  `reaccion` varchar(16) DEFAULT NULL,
  `responde_a` varchar(150) DEFAULT NULL,
  `media_id` varchar(120) DEFAULT NULL,
  `texto` text DEFAULT NULL,
  `referral_json` text DEFAULT NULL,
  `media_path` varchar(255) DEFAULT NULL,
  `media_mime` varchar(100) DEFAULT NULL,
  `media_nombre` varchar(180) DEFAULT NULL,
  `template_name` varchar(120) DEFAULT NULL,
  `estado` enum('queued','sent','delivered','read','failed') DEFAULT NULL,
  `error_msg` varchar(400) DEFAULT NULL,
  `enviado_por` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `desde_app` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wm_waid` (`wa_message_id`),
  KEY `idx_wm_hilo` (`conversacion_id`,`id`),
  KEY `idx_wm_estado` (`estado`)
) ENGINE=InnoDB AUTO_INCREMENT=13737 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_numero_usuarios`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_numero_usuarios` (
  `numero_id` int(10) unsigned NOT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`numero_id`,`usuario_id`),
  KEY `idx_usr` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_numeros`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_numeros` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `phone_number_id` varchar(32) NOT NULL,
  `waba_id` varchar(32) DEFAULT NULL,
  `etiqueta` varchar(80) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `verified_name` varchar(120) DEFAULT NULL,
  `calidad` varchar(20) DEFAULT NULL,
  `estado` varchar(30) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `verificado_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_num` (`phone_number_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wa_respuestas_rapidas`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_respuestas_rapidas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `atajo` varchar(40) NOT NULL,
  `texto` text NOT NULL,
  `creado_por` int(10) unsigned DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rr_atajo` (`atajo`),
  KEY `fk_rr_user` (`creado_por`),
  CONSTRAINT `fk_rr_user` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `whatsapp_campanas`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_campanas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kapso_broadcast_id` varchar(64) DEFAULT NULL,
  `nombre` varchar(120) NOT NULL,
  `template_id` varchar(64) NOT NULL,
  `template_name` varchar(120) NOT NULL,
  `template_lang` varchar(10) DEFAULT NULL,
  `param_keys` text DEFAULT NULL,
  `segmento` varchar(40) NOT NULL,
  `segmento_json` text DEFAULT NULL,
  `total_destinatarios` int(10) unsigned NOT NULL DEFAULT 0,
  `excluidos` int(10) unsigned NOT NULL DEFAULT 0,
  `estado` varchar(20) NOT NULL DEFAULT 'draft',
  `iniciada_at` datetime DEFAULT NULL,
  `finalizada_at` datetime DEFAULT NULL,
  `stats_json` text DEFAULT NULL,
  `creado_por` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kapso` (`kapso_broadcast_id`),
  KEY `idx_estado` (`estado`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `whatsapp_inbound`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_inbound` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `wa_message_id` varchar(128) DEFAULT NULL,
  `telefono` varchar(20) NOT NULL,
  `cliente_id` int(10) unsigned DEFAULT NULL,
  `tipo` varchar(20) DEFAULT NULL,
  `texto` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_msg` (`wa_message_id`),
  KEY `idx_telefono` (`telefono`),
  KEY `idx_cliente` (`cliente_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6255 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `whatsapp_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned DEFAULT NULL,
  `event_code` varchar(100) NOT NULL DEFAULT '',
  `telefono` varchar(20) NOT NULL DEFAULT '',
  `template_name` varchar(100) NOT NULL DEFAULT '',
  `params_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`params_json`)),
  `context_json` longtext DEFAULT NULL,
  `wa_message_id` varchar(150) DEFAULT NULL,
  `estado` enum('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued',
  `error_msg` varchar(400) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wl_cliente` (`cliente_id`),
  KEY `idx_wl_event` (`event_code`),
  KEY `idx_wl_estado` (`estado`),
  KEY `idx_wl_wa_id` (`wa_message_id`)
) ENGINE=InnoDB AUTO_INCREMENT=434 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `whatsapp_templates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_templates` (
  `event_code` varchar(100) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `language_code` varchar(10) NOT NULL DEFAULT 'es',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `param_keys` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Lista de claves del $context que se pasan como params al template' CHECK (json_valid(`param_keys`)),
  `body_text` text DEFAULT NULL,
  `estado_meta` varchar(20) DEFAULT NULL,
  `categoria` varchar(20) DEFAULT NULL,
  `url_button_key` varchar(50) DEFAULT NULL,
  `header_media_url` varchar(500) DEFAULT NULL,
  `notas` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`event_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping routines for database 'groupglo_sistema'
--

--
-- Final view structure for view `v_auditorias_proximas`
--

/*!50001 DROP VIEW IF EXISTS `v_auditorias_proximas`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = latin1 */;
/*!50001 SET character_set_results     = latin1 */;
/*!50001 SET collation_connection      = latin1_swedish_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`groupglo_sistema`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_auditorias_proximas` AS select `a`.`id` AS `id`,`cl`.`codigo_interno` AS `codigo_interno`,`cl`.`razon_social` AS `razon_social`,`n`.`codigo` AS `norma`,`a`.`tipo` AS `tipo`,`a`.`fecha_programada` AS `fecha_programada`,to_days(`a`.`fecha_programada`) - to_days(curdate()) AS `dias_restantes`,`a`.`resultado` AS `resultado` from (((`auditorias` `a` join `certificados` `c` on(`c`.`id` = `a`.`certificado_id`)) join `clientes` `cl` on(`cl`.`id` = `c`.`cliente_id`)) join `normas_iso` `n` on(`n`.`id` = `c`.`norma_iso_id`)) where `a`.`fecha_programada` between curdate() and curdate() + interval 60 day and `a`.`resultado` = 'pendiente' and `a`.`deleted_at` is null and `c`.`deleted_at` is null and `cl`.`deleted_at` is null order by `a`.`fecha_programada` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_certificados_por_vencer`
--

/*!50001 DROP VIEW IF EXISTS `v_certificados_por_vencer`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = latin1 */;
/*!50001 SET character_set_results     = latin1 */;
/*!50001 SET collation_connection      = latin1_swedish_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`groupglo_sistema`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_certificados_por_vencer` AS select `c`.`id` AS `id`,`cl`.`codigo_interno` AS `codigo_interno`,`cl`.`razon_social` AS `razon_social`,`n`.`codigo` AS `norma`,`cc`.`nombre` AS `casa_certificadora`,`c`.`numero_certificado` AS `numero_certificado`,`c`.`fecha_vencimiento` AS `fecha_vencimiento`,to_days(`c`.`fecha_vencimiento`) - to_days(curdate()) AS `dias_restantes`,`c`.`estado` AS `estado` from (((`certificados` `c` join `clientes` `cl` on(`cl`.`id` = `c`.`cliente_id`)) join `normas_iso` `n` on(`n`.`id` = `c`.`norma_iso_id`)) join `casas_certificadoras` `cc` on(`cc`.`id` = `c`.`casa_certificadora_id`)) where `c`.`fecha_vencimiento` between curdate() and curdate() + interval 90 day and `c`.`deleted_at` is null and `cl`.`deleted_at` is null order by `c`.`fecha_vencimiento` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_envios_pendientes`
--

/*!50001 DROP VIEW IF EXISTS `v_envios_pendientes`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = latin1 */;
/*!50001 SET character_set_results     = latin1 */;
/*!50001 SET collation_connection      = latin1_swedish_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`groupglo_sistema`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_envios_pendientes` AS select `e`.`id` AS `id`,`cl`.`codigo_interno` AS `codigo_interno`,`cl`.`razon_social` AS `razon_social`,`ag`.`nombre` AS `agencia`,`es`.`nombre` AS `estado`,`e`.`fecha_envio` AS `fecha_envio`,`e`.`codigo_envio` AS `codigo_envio`,to_days(curdate()) - to_days(`e`.`fecha_envio`) AS `dias_en_transito` from (((`envios` `e` join `clientes` `cl` on(`cl`.`id` = `e`.`cliente_id`)) join `agencias_envio` `ag` on(`ag`.`id` = `e`.`agencia_envio_id`)) join `estados_envio` `es` on(`es`.`id` = `e`.`estado_envio_id`)) where `e`.`fecha_recibido` is null and `e`.`fecha_envio` is not null and `e`.`deleted_at` is null and `cl`.`deleted_at` is null order by `e`.`fecha_envio` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-13 10:52:33
