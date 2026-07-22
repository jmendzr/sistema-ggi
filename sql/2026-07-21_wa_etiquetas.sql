CREATE TABLE IF NOT EXISTS wa_etiquetas (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(60)  NOT NULL,
  color  VARCHAR(20)  NOT NULL DEFAULT 'neutral',
  activo TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_etq (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wa_conversacion_etiquetas (
  conversacion_id INT UNSIGNED NOT NULL,
  etiqueta_id     INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (conversacion_id, etiqueta_id),
  KEY idx_wce_etq (etiqueta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado real de la plantilla en Meta (APPROVED/REJECTED/PENDING).
-- Se sincroniza con GET /{WABA_ID}/message_templates. El inbox solo ofrece
-- las APPROVED: ofrecer una rechazada es un fallo garantizado.
ALTER TABLE whatsapp_templates ADD COLUMN estado_meta VARCHAR(20) NULL AFTER body_text;
