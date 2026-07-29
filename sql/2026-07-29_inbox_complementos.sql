-- ============================================================
--  Inbox: complementos (notas internas + respuestas rápidas)
--  2026-07-29
-- ============================================================

-- Notas internas por conversación: privadas del equipo, NUNCA se envían al cliente.
CREATE TABLE IF NOT EXISTS wa_conversacion_notas (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  conversacion_id INT UNSIGNED NOT NULL,
  usuario_id      INT UNSIGNED NULL,
  nota            TEXT NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wacn_conv (conversacion_id),
  CONSTRAINT fk_wacn_conv FOREIGN KEY (conversacion_id)
    REFERENCES wa_conversaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_wacn_user FOREIGN KEY (usuario_id)
    REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Respuestas rápidas (canned) compartidas por todo el equipo.
CREATE TABLE IF NOT EXISTS wa_respuestas_rapidas (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  atajo      VARCHAR(40)  NOT NULL,
  texto      TEXT         NOT NULL,
  creado_por INT UNSIGNED NULL,
  activo     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rr_atajo (atajo),
  CONSTRAINT fk_rr_user FOREIGN KEY (creado_por)
    REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Semilla de ejemplos útiles (se pueden editar/borrar desde la UI).
INSERT INTO wa_respuestas_rapidas (atajo, texto) VALUES
 ('saludo',  'Estimado(a), un gusto saludarle desde Group Global ISO. ¿En qué podemos ayudarle?'),
 ('horario', 'Nuestro horario de atención es de lunes a viernes de 9:00 a.m. a 6:00 p.m. Con gusto le atenderemos.'),
 ('pago',    'Puede realizar el pago a la siguiente cuenta y enviarnos el comprobante por este medio. ¡Gracias!'),
 ('gracias', '¡Muchas gracias por su preferencia! Quedamos atentos a cualquier consulta.')
ON DUPLICATE KEY UPDATE atajo = atajo;
