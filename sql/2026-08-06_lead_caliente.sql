-- Marca de "lead caliente": conversación cuyo cliente pulsó "Quiero aprovecharlo"
-- en el aviso de cotización por vencer. Se fija arriba y notifica al ejecutivo.
ALTER TABLE wa_conversaciones ADD COLUMN lead_caliente_at DATETIME NULL AFTER fijada;
