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

-- Campañas nativas: Meta no tiene endpoint de broadcast, cada mensaje es una
-- llamada. La campaña se encola y el cron la despacha por lotes.
CREATE TABLE IF NOT EXISTS wa_campana_destinatarios (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campana_id    INT UNSIGNED NOT NULL,
  cliente_id    INT UNSIGNED NULL,
  telefono      VARCHAR(20)  NOT NULL,
  empresa       VARCHAR(200) NULL,
  params_json   TEXT         NULL,
  estado        ENUM('pendiente','enviado','entregado','leido','fallido') NOT NULL DEFAULT 'pendiente',
  wa_message_id VARCHAR(150) NULL,
  error_msg     VARCHAR(400) NULL,
  intentos      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  enviado_at    DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_camp_tel (campana_id, telefono),
  KEY idx_cola (campana_id, estado, id),
  KEY idx_waid (wa_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE whatsapp_campanas ADD COLUMN template_lang VARCHAR(10) NULL AFTER template_name;
ALTER TABLE whatsapp_campanas ADD COLUMN param_keys    TEXT        NULL AFTER template_lang;
ALTER TABLE whatsapp_campanas ADD COLUMN iniciada_at   DATETIME    NULL AFTER estado;
ALTER TABLE whatsapp_campanas ADD COLUMN finalizada_at DATETIME    NULL AFTER iniciada_at;

-- Categoria real de la plantilla en Meta (UTILITY/MARKETING/AUTHENTICATION).
-- No es cosmetica: Marketing cuesta mas por mensaje y exige consentimiento.
ALTER TABLE whatsapp_templates ADD COLUMN categoria VARCHAR(20) NULL AFTER estado_meta;

-- Registro propio de numeros: el sistema nacio atado a un solo
-- META_WA_PHONE_NUMBER_ID del .env, pero la empresa puede tener varios
-- repartidos en mas de una cuenta de WhatsApp Business.
CREATE TABLE IF NOT EXISTS wa_numeros (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone_number_id VARCHAR(32)  NOT NULL,
  waba_id         VARCHAR(32)  NULL,
  etiqueta        VARCHAR(80)  NULL,
  telefono        VARCHAR(30)  NULL,
  verified_name   VARCHAR(120) NULL,
  calidad         VARCHAR(20)  NULL,
  estado          VARCHAR(30)  NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  verificado_at   DATETIME     NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_num (phone_number_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
