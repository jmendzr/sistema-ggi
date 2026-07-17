-- ============================================================
-- 12: Agenda de llamadas con recordatorio push
--     + preferencia de horario de contacto del cliente
-- Fecha: 2026-07-15
-- ============================================================

-- Preferencia del cliente (ej: "No llamar después de las 5 PM")
ALTER TABLE clientes
  ADD COLUMN horario_contacto VARCHAR(120) NULL DEFAULT NULL AFTER contacto_telefono;

-- Llamadas programadas: quién llama a qué cliente, cuándo y con qué aviso.
CREATE TABLE IF NOT EXISTS llamadas_programadas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id    INT UNSIGNED NOT NULL,
  cotizacion_id INT UNSIGNED NULL DEFAULT NULL,   -- contexto opcional (propuesta)
  usuario_id    INT UNSIGNED NOT NULL,            -- quién debe llamar (recibe el push)
  fecha_hora    DATETIME NOT NULL,                -- hora acordada con el cliente
  aviso_min     TINYINT UNSIGNED NOT NULL DEFAULT 15,  -- minutos antes del 1er aviso
  nota          VARCHAR(500) NULL DEFAULT NULL,
  estado        ENUM('pendiente','notificada','realizada','cancelada') NOT NULL DEFAULT 'pendiente',
  aviso_previo_at DATETIME NULL DEFAULT NULL,     -- push "en X min" enviado
  aviso_hora_at   DATETIME NULL DEFAULT NULL,     -- push "es ahora" enviado
  nota_cierre   VARCHAR(500) NULL DEFAULT NULL,   -- resultado al marcar realizada
  created_by    INT UNSIGNED NULL DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_llam_usuario_estado (usuario_id, estado),
  KEY idx_llam_fecha (fecha_hora),
  KEY idx_llam_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
