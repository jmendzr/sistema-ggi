-- Workflows más robustos: nodos de Condición (ramificar) e IA (responder texto libre).
ALTER TABLE wa_bot_nodos
  MODIFY COLUMN tipo ENUM('mensaje','menu','pedir_ruc','pedir_normas','derivar','fin','condicion','ia')
  NOT NULL DEFAULT 'mensaje';

-- Reglas de un nodo de Condición: se evalúan en orden; la primera que coincide manda.
CREATE TABLE IF NOT EXISTS wa_bot_reglas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nodo_id       INT NOT NULL,
  orden         INT NOT NULL DEFAULT 0,
  campo         ENUM('ruc','normas','razon','texto') NOT NULL DEFAULT 'texto',
  operador      ENUM('tiene','no_tiene','contiene','no_contiene') NOT NULL DEFAULT 'contiene',
  valor         VARCHAR(190) NULL,
  destino_clave VARCHAR(60) NOT NULL,
  KEY k_nodo (nodo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
