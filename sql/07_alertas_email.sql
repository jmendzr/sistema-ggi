-- ============================================================
--  GGI SISTEMA — Tabla de control de alertas de email enviadas
--  Evita reenviar el mismo email el mismo día
--  Ejecutar en phpMyAdmin sobre la BD: groupglo_sistema
-- ============================================================

CREATE TABLE `alertas_email` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `certificado_id`  INT UNSIGNED NOT NULL,
  `tipo_alerta`     ENUM('90_dias','60_dias','30_dias') NOT NULL,
  `email_destino`   VARCHAR(150) NOT NULL,
  `enviado_at`      TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alerta` (`certificado_id`, `tipo_alerta`),
  KEY `idx_cert` (`certificado_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  FIN
-- ============================================================
