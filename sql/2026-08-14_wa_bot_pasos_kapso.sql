-- Pool completo de pasos estilo Kapso: Esperar respuesta, Webhook, Registrar evento.
ALTER TABLE wa_bot_nodos
  MODIFY COLUMN tipo ENUM('mensaje','menu','pedir_ruc','pedir_normas','condicion','ia','send_template','esperar','webhook','emit','derivar','fin')
  NOT NULL DEFAULT 'mensaje';

CREATE TABLE IF NOT EXISTS wa_bot_eventos (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  conversacion_id INT NULL,
  evento          VARCHAR(120) NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_evt (evento), KEY k_conv (conversacion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
