-- Lienzo visual del bot: posición de cada nodo + config JSON por paso + tipo send_template.
ALTER TABLE wa_bot_nodos
  ADD COLUMN pos_x INT NULL AFTER orden,
  ADD COLUMN pos_y INT NULL AFTER pos_x,
  ADD COLUMN config_json TEXT NULL AFTER pos_y;

ALTER TABLE wa_bot_nodos
  MODIFY COLUMN tipo ENUM('mensaje','menu','pedir_ruc','pedir_normas','condicion','ia','send_template','derivar','fin')
  NOT NULL DEFAULT 'mensaje';
