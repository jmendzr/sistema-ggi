-- Constructor de flujos del Bot de WhatsApp (guiado por datos).
-- El flujo deja de estar en código: vive en estas tablas y se edita desde
-- Mantenimiento → Bot WhatsApp. Cada paso es un NODO; los botones de un nodo
-- de menú enlazan a otros nodos; los disparadores saltan a un nodo por palabra clave.

-- Nodo actual de la conversación dentro del flujo.
ALTER TABLE wa_conversaciones
  ADD COLUMN bot_nodo VARCHAR(60) NULL AFTER bot_estado;

-- Nodos del flujo.
CREATE TABLE IF NOT EXISTS wa_bot_nodos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  clave         VARCHAR(60)  NOT NULL,
  nombre        VARCHAR(120) NOT NULL,
  tipo          ENUM('mensaje','menu','pedir_ruc','pedir_normas','derivar','fin') NOT NULL DEFAULT 'mensaje',
  texto         TEXT NULL,
  es_inicio     TINYINT(1) NOT NULL DEFAULT 0,
  siguiente_clave VARCHAR(60) NULL,     -- a dónde ir tras enviar (mensaje / pedir_*)
  fijar_normas  VARCHAR(255) NULL,      -- al pasar por el nodo, fija las normas (ej. Pack)
  etiqueta_nombre VARCHAR(60) NULL,     -- para 'derivar': etiqueta a aplicar
  orden         INT NOT NULL DEFAULT 0,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Botones de un nodo de menú (cada uno enlaza a otro nodo por su clave).
CREATE TABLE IF NOT EXISTS wa_bot_botones (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nodo_id       INT NOT NULL,
  orden         INT NOT NULL DEFAULT 0,
  label         VARCHAR(30) NOT NULL,
  destino_clave VARCHAR(60) NOT NULL,
  KEY k_nodo (nodo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Disparadores por palabra clave: en cualquier punto, saltan a un nodo.
CREATE TABLE IF NOT EXISTS wa_bot_triggers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  palabras      TEXT NOT NULL,          -- palabras/frases separadas por coma
  destino_clave VARCHAR(60) NOT NULL,
  prioridad     INT NOT NULL DEFAULT 0,
  activo        TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Flujo por defecto (replica el bot actual) ──────────────────────────────
INSERT INTO wa_bot_nodos (clave, nombre, tipo, texto, es_inicio, siguiente_clave, fijar_normas, etiqueta_nombre, orden) VALUES
('inicio', 'Bienvenida (menú)', 'menu',
 '👋 ¡Hola! Gracias por comunicarte con *Group Global ISO*.\n🏆 Especialistas en certificaciones ISO con acreditación internacional (IAF) · entrega en 2–3 días hábiles · vigencia 3 años.\n\n🎉 *Promo del mes — Pack Cuádruple*\nISO 9001 · 14001 · 45001 · 37001\nantes S/ 6,018 → 🔥 *S/ 4,720* (ahorras S/ 1,298)\n\n¿Cómo te ayudo?',
 1, NULL, NULL, NULL, 0),
('pack', 'Eligió el Pack', 'mensaje',
 '😊 ¡Excelente! El Pack Cuádruple incluye ISO 9001, 14001, 45001 y 37001 por *S/ 4,720*.',
 0, 'ruc', 'Pack Cuádruple (ISO 9001, 14001, 45001, 37001)', NULL, 1),
('catalogo', 'Elegir normas (catálogo)', 'mensaje',
 '¡Con gusto! Certificamos, entre otras:\n\n*Sistemas de gestión:* ISO 9001 (Calidad), 14001 (Ambiental), 45001 (SST), 37001 (Antisoborno), 27001 (Seg. de la información), 22000 (Inocuidad), 50001 (Energía), 13485 (Disp. médicos), 21001 (Educación), 20000-1 (TI).\n*Otras:* SA8000 (Resp. social), BPL, BPM.',
 0, 'normas', NULL, NULL, 2),
('normas', 'Pedir norma(s)', 'pedir_normas',
 '📌 Dime *cuál(es)* norma(s) necesitas (una o varias).',
 0, 'ruc', NULL, NULL, 3),
('ruc', 'Pedir RUC', 'pedir_ruc',
 'Perfecto 🙌 Para preparar tu propuesta, envíame tu *RUC* (11 dígitos).',
 0, 'cierre', NULL, NULL, 4),
('cierre', 'Cierre + derivar al asesor', 'derivar',
 '¡Perfecto! ✅ Registramos:\n✔ Norma(s): {normas}\n✔ RUC: {ruc}{razon}\n\n📩 En breve nuestro asesor te atenderá para preparar tu propuesta. ¡Muchas gracias por confiar en *Group Global ISO*!',
 0, NULL, NULL, 'Datos completos', 5),
('info', 'Más información (derivar)', 'derivar',
 'Con gusto 🙌 Nuestro asesor de servicio te atenderá a la brevedad.',
 0, NULL, NULL, 'Requiere seguimiento', 6);

INSERT INTO wa_bot_botones (nodo_id, orden, label, destino_clave)
SELECT n.id, x.orden, x.label, x.destino FROM wa_bot_nodos n
JOIN (
  SELECT 'inicio' AS clave, 0 AS orden, 'Quiero el pack'    AS label, 'pack'     AS destino
  UNION ALL SELECT 'inicio', 1, 'Elegir mis normas', 'catalogo'
  UNION ALL SELECT 'inicio', 2, 'Más información',   'info'
) x ON x.clave = n.clave;

INSERT INTO wa_bot_triggers (palabras, destino_clave, prioridad) VALUES
('asesor, humano, persona, ejecutivo, agente, vendedor', 'info', 10);
