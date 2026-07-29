-- Auditoría 2026-07-29: índices de apoyo en columnas de filtro/join frecuentes.
-- Impacto bajo hoy (tablas pequeñas) pero previene degradación al crecer.
ALTER TABLE certificados   ADD INDEX idx_cert_cotizacion (cotizacion_id);
ALTER TABLE envios         ADD INDEX idx_env_cotizacion (cotizacion_id);
ALTER TABLE notificaciones ADD INDEX idx_notif_leida (usuario_id, leida);
ALTER TABLE clientes       ADD INDEX idx_cli_revision (revision_gerente);
