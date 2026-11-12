-- Bot de recepción de leads en WhatsApp: estado + datos capturados por conversación.
ALTER TABLE wa_conversaciones
  ADD COLUMN bot_estado VARCHAR(20) NULL AFTER auto_fuera_at,
  ADD COLUMN bot_ruc    VARCHAR(11) NULL AFTER bot_estado,
  ADD COLUMN bot_certs  VARCHAR(255) NULL AFTER bot_ruc,
  ADD COLUMN bot_razon  VARCHAR(200) NULL AFTER bot_certs,
  ADD COLUMN bot_intentos TINYINT NOT NULL DEFAULT 0 AFTER bot_razon,
  ADD COLUMN bot_at DATETIME NULL AFTER bot_intentos;
