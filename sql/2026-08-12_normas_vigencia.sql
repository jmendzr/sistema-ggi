-- ============================================================
--  Vigencia del certificado por norma (data-driven)
--  Antes: el vencimiento se calculaba SIEMPRE a +3 años (hardcoded),
--  lo cual es incorrecto para normas de 1 año como BPL.
--  Ahora cada norma define su vigencia en meses.
-- ============================================================

ALTER TABLE `normas_iso`
  ADD COLUMN `meses_vigencia` SMALLINT UNSIGNED NOT NULL DEFAULT 36
  COMMENT 'Vigencia del certificado en meses (36 = 3 años ISO, 12 = 1 año BPL)'
  AFTER `nombre`;

-- BPL (Buenas Prácticas Laborales): vigencia de 1 año.
UPDATE `normas_iso` SET `meses_vigencia` = 12 WHERE `codigo` = 'BPL';

-- Corrección de datos: recalcular vencimiento de los certificados BPL ya emitidos
-- (estaban a +3 años por el bug). Vencimiento = emisión + 1 año - 1 día.
-- Las auditorías de seguimiento (+1/+2 años) no aplican a un ciclo de 1 año → NULL.
UPDATE `certificados`
   SET `fecha_vencimiento` = DATE_SUB(DATE_ADD(`fecha_emision`, INTERVAL 12 MONTH), INTERVAL 1 DAY),
       `fecha_auditoria_1` = NULL,
       `fecha_auditoria_2` = NULL
 WHERE `norma_iso_id` = (SELECT id FROM `normas_iso` WHERE `codigo` = 'BPL')
   AND `fecha_emision` IS NOT NULL
   AND `deleted_at` IS NULL;
