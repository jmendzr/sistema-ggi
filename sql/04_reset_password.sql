-- ============================================================
--  GGI SISTEMA — Agregar columnas para recuperación de contraseña
--  Ejecutar en phpMyAdmin sobre la BD: groupglo_sistema
-- ============================================================

ALTER TABLE `usuarios`
  ADD COLUMN `reset_token`      VARCHAR(64)  DEFAULT NULL AFTER `ultimo_acceso`,
  ADD COLUMN `reset_token_exp`  DATETIME     DEFAULT NULL AFTER `reset_token`;

-- Índice para búsqueda rápida por token
ALTER TABLE `usuarios`
  ADD INDEX `idx_reset_token` (`reset_token`);

-- ============================================================
--  FIN
-- ============================================================
