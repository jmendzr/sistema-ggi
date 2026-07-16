-- ============================================================
-- ACTA DE ALCANCE - REGISTRO DE CAMBIOS
-- ============================================================

CREATE TABLE IF NOT EXISTS `acta_alcance_cambios` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `documento_id`       INT UNSIGNED NOT NULL,
  `cliente_id`         INT UNSIGNED NOT NULL,
  `cotizacion_id`      INT UNSIGNED,
  `cambios_notas`      LONGTEXT NOT NULL,
  `agregado_por`       INT UNSIGNED NOT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_id` (`documento_id`),
  KEY `idx_cliente_id` (`cliente_id`),
  CONSTRAINT `fk_aac_documento` FOREIGN KEY (`documento_id`) REFERENCES `documentos_cliente` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aac_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aac_usuario` FOREIGN KEY (`agregado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de cambios/notas agregadas al Acta de Alcance';
