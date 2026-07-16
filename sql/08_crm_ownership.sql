-- ============================================================
-- MIGRACIÓN: Modelo de propiedad CRM — ejecutivos y empresas
-- Archivo:   08_crm_ownership.sql
-- Fecha:     2026-06-01
-- Tipo:      SEGURO — solo operaciones ADD (nunca DROP/MODIFY)
-- Revertir:  Ver sección ROLLBACK al final
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- PASO 1: Agregar columnas a clientes
--   created_by_user_id → Ejecutivo que captó originalmente la empresa (INMUTABLE)
--   responsible_user_id → Ejecutivo actualmente responsable (ACTUALIZABLE)
-- ────────────────────────────────────────────────────────────

ALTER TABLE clientes
  ADD COLUMN created_by_user_id INT UNSIGNED NULL
    COMMENT 'Ejecutivo que captó originalmente la empresa — campo inmutable tras creación'
    AFTER ejecutivo_id,

  ADD COLUMN responsible_user_id INT UNSIGNED NULL
    COMMENT 'Ejecutivo actualmente responsable de la cuenta — cambia al aceptarse propuesta de otro ejecutivo'
    AFTER created_by_user_id,

  ADD INDEX idx_cli_responsible (responsible_user_id),
  ADD INDEX idx_cli_created_by  (created_by_user_id),

  ADD CONSTRAINT fk_clientes_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL,

  ADD CONSTRAINT fk_clientes_responsible
    FOREIGN KEY (responsible_user_id) REFERENCES usuarios(id) ON DELETE SET NULL;


-- ────────────────────────────────────────────────────────────
-- PASO 2: Tabla de historial de cambios de responsable
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS historial_responsables_cliente (
  id                   INT UNSIGNED        NOT NULL AUTO_INCREMENT,
  cliente_id           INT UNSIGNED        NOT NULL,
  responsable_anterior INT UNSIGNED        NULL COMMENT 'NULL = sin responsable previo (primera asignación)',
  responsable_nuevo    INT UNSIGNED        NULL COMMENT 'NULL = responsable quitado (raro)',
  motivo               ENUM(
                         'creacion',            -- cliente creado por primera vez
                         'aceptacion_propuesta', -- propuesta de otro ejecutivo aceptada
                         'reasignacion_manual',  -- gerente/admin reasignó manualmente
                         'migracion'             -- backfill de datos históricos
                       ) NOT NULL DEFAULT 'creacion',
  cotizacion_id        INT UNSIGNED        NULL COMMENT 'Propuesta que generó el cambio (solo aceptacion_propuesta)',
  registrado_por       INT UNSIGNED        NULL COMMENT 'Usuario que ejecutó el cambio',
  notas                TEXT                NULL,
  created_at           TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  INDEX idx_hrc_cliente  (cliente_id),
  INDEX idx_hrc_nuevo    (responsable_nuevo),
  INDEX idx_hrc_anterior (responsable_anterior),
  INDEX idx_hrc_fecha    (created_at),

  CONSTRAINT fk_hrc_cliente
    FOREIGN KEY (cliente_id)           REFERENCES clientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_hrc_anterior
    FOREIGN KEY (responsable_anterior) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_hrc_nuevo
    FOREIGN KEY (responsable_nuevo)    REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_hrc_cotizacion
    FOREIGN KEY (cotizacion_id)        REFERENCES cotizaciones(id) ON DELETE SET NULL,
  CONSTRAINT fk_hrc_registrado_por
    FOREIGN KEY (registrado_por)       REFERENCES usuarios(id) ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Historial de cambios de ejecutivo responsable por empresa';


-- ────────────────────────────────────────────────────────────
-- PASO 3: MIGRACIÓN de datos existentes
--   Estrategia: inferir responsable original desde ejecutivo_id
--   Los dos campos comienzan idénticos a ejecutivo_id
--   Con el tiempo divergirán a medida que haya transferencias
-- ────────────────────────────────────────────────────────────

-- 3a. Poblar created_by_user_id y responsible_user_id desde ejecutivo_id
UPDATE clientes
SET
  created_by_user_id  = ejecutivo_id,
  responsible_user_id = ejecutivo_id
WHERE ejecutivo_id IS NOT NULL
  AND created_by_user_id IS NULL;   -- Idempotente: no sobreescribir si ya existe

-- 3b. Crear registros de migración en historial (uno por empresa con ejecutivo asignado)
INSERT INTO historial_responsables_cliente
  (cliente_id, responsable_anterior, responsable_nuevo, motivo, notas, created_at)
SELECT
  id,
  NULL,
  ejecutivo_id,
  'migracion',
  CONCAT('Backfill automático — responsable inferido del campo ejecutivo_id existente. Estado empresa: ', estado),
  COALESCE(created_at, NOW())
FROM clientes
WHERE ejecutivo_id IS NOT NULL;

-- 3c. Verificar resultado (para confirmar manualmente antes de desplegar)
-- SELECT
--   COUNT(*) AS total_clientes,
--   COUNT(created_by_user_id) AS con_created_by,
--   COUNT(responsible_user_id) AS con_responsible
-- FROM clientes;
--
-- SELECT COUNT(*) AS registros_historial FROM historial_responsables_cliente;


-- ────────────────────────────────────────────────────────────
-- ROLLBACK (ejecutar solo si es necesario revertir)
-- ────────────────────────────────────────────────────────────
-- ALTER TABLE clientes
--   DROP FOREIGN KEY fk_clientes_created_by,
--   DROP FOREIGN KEY fk_clientes_responsible,
--   DROP INDEX idx_cli_responsible,
--   DROP INDEX idx_cli_created_by,
--   DROP COLUMN created_by_user_id,
--   DROP COLUMN responsible_user_id;
--
-- DROP TABLE IF EXISTS historial_responsables_cliente;
