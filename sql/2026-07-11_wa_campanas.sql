-- 2026-07-11 — Campañas WhatsApp (Kapso Broadcasts) + captura de respuestas entrantes.

-- Mensajes entrantes de clientes (webhook Meta via Kapso). Permite saber quién respondió.
CREATE TABLE IF NOT EXISTS whatsapp_inbound (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  wa_message_id VARCHAR(128) NULL,
  telefono      VARCHAR(20)  NOT NULL,
  cliente_id    INT UNSIGNED NULL,
  tipo          VARCHAR(20)  NULL,
  texto         TEXT         NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_msg (wa_message_id),
  KEY idx_telefono (telefono),
  KEY idx_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro local de campañas masivas (espejo de Kapso whatsapp_broadcasts).
CREATE TABLE IF NOT EXISTS whatsapp_campanas (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kapso_broadcast_id  VARCHAR(64)  NULL,
  nombre              VARCHAR(120) NOT NULL,
  template_id         VARCHAR(64)  NOT NULL,
  template_name       VARCHAR(120) NOT NULL,
  segmento            VARCHAR(40)  NOT NULL,
  segmento_json       TEXT         NULL,
  total_destinatarios INT UNSIGNED NOT NULL DEFAULT 0,
  excluidos           INT UNSIGNED NOT NULL DEFAULT 0,
  estado              VARCHAR(20)  NOT NULL DEFAULT 'draft',
  stats_json          TEXT         NULL,
  creado_por          INT UNSIGNED NOT NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_kapso (kapso_broadcast_id),
  KEY idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
