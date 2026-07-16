-- ============================================================
-- ACTA DE ALCANCE - ESTADO Y CONTROL DE CAMBIOS
-- ============================================================

ALTER TABLE `documentos_cliente` ADD COLUMN IF NOT EXISTS `estado_acta` ENUM('vigente','pendiente_cambios','confirmada') DEFAULT 'vigente' COMMENT 'vigente=lista para aprobar, pendiente_cambios=ejecutivo solicitó cambios, confirmada=aprobada';
