-- ═══════════════════════════════════════════════════════════
-- Tabla: cotizacion_seguimientos
-- Registra el seguimiento comercial de cotizaciones enviadas
-- ═══════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `cotizacion_seguimientos` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cotizacion_id`  int(10) UNSIGNED NOT NULL,
  `usuario_id`     int(10) UNSIGNED NOT NULL,
  `tipo`           enum('llamada','email','reunion','whatsapp','otro') NOT NULL DEFAULT 'llamada',
  `notas`          text DEFAULT NULL,
  `resultado`      enum('sin_respuesta','interesado','pidio_tiempo','rechazado','aceptado') NOT NULL DEFAULT 'sin_respuesta',
  `proxima_accion` date DEFAULT NULL,
  `created_at`     timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cotizacion` (`cotizacion_id`),
  KEY `idx_usuario` (`usuario_id`),
  CONSTRAINT `fk_seg_cotizacion` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_seg_usuario`    FOREIGN KEY (`usuario_id`)    REFERENCES `usuarios`     (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
