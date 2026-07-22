-- Inbox multiagente de WhatsApp — fase 1: modelo de datos
-- Una fila por contacto; el hilo unificado vive en wa_mensajes.

CREATE TABLE IF NOT EXISTS wa_conversaciones (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  telefono               VARCHAR(20)  NOT NULL,
  phone_number_id        VARCHAR(32)  NOT NULL,           -- número GGI que atiende (multi-número a futuro)
  cliente_id             INT UNSIGNED NULL,
  asignado_a             INT UNSIGNED NULL,               -- usuarios.id; NULL = libre
  asignado_at            DATETIME     NULL,
  estado                 ENUM('abierta','resuelta') NOT NULL DEFAULT 'abierta',
  ventana_expira_at      DATETIME     NULL,               -- último entrante + 24h (regla de Meta)
  ultimo_mensaje_at      DATETIME     NULL,
  ultimo_mensaje_preview VARCHAR(200) NULL,
  no_leidos              INT UNSIGNED NOT NULL DEFAULT 0,
  created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_conv (telefono, phone_number_id),
  KEY idx_wc_asignado (asignado_a),
  KEY idx_wc_cliente (cliente_id),
  KEY idx_wc_bandeja (estado, ultimo_mensaje_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wa_mensajes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversacion_id INT UNSIGNED NOT NULL,
  wa_message_id   VARCHAR(150) NULL,                      -- UNIQUE: el webhook puede reintentar
  direccion       ENUM('in','out') NOT NULL,
  tipo            VARCHAR(20)  NOT NULL DEFAULT 'text',
  texto           TEXT         NULL,
  media_path      VARCHAR(255) NULL,
  media_mime      VARCHAR(100) NULL,
  template_name   VARCHAR(120) NULL,                      -- si salió como plantilla
  estado          ENUM('queued','sent','delivered','read','failed') NULL,
  error_msg       VARCHAR(400) NULL,
  enviado_por     INT UNSIGNED NULL,                      -- usuarios.id; NULL = automático del sistema
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wm_waid (wa_message_id),
  KEY idx_wm_hilo (conversacion_id, id),
  KEY idx_wm_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cuerpo de la plantilla, para reconstruir en el hilo el texto que vio el cliente.
-- Se rellena sincronizando desde GET /{WABA_ID}/message_templates.
ALTER TABLE whatsapp_templates ADD COLUMN body_text TEXT NULL AFTER param_keys;

-- Permiso del nuevo modulo Inbox (la tabla de permisos es rol_permisos)
INSERT INTO rol_permisos (rol, modulo, activo) VALUES
  ('gerente_operaciones', 'inbox', 1),
  ('ejecutivo_ventas',    'inbox', 1);

-- Adjuntos: Meta manda un identificador, no el archivo. La URL de descarga
-- caduca en minutos; el media_id dura ~30 dias, asi que se descarga bajo
-- demanda y se cachea en storage/wa_media (fuera de git).
ALTER TABLE wa_mensajes ADD COLUMN media_id     VARCHAR(120) NULL AFTER tipo;
ALTER TABLE wa_mensajes ADD COLUMN media_nombre VARCHAR(180) NULL AFTER media_mime;
