-- Anti-duplicado de los 2 avisos de "cotización por vencer" (3 días y 1 día antes).
ALTER TABLE cotizaciones
  ADD COLUMN aviso_vence_3d_at DATETIME NULL AFTER fecha_vencimiento,
  ADD COLUMN aviso_vence_1d_at DATETIME NULL AFTER aviso_vence_3d_at;
