-- ============================================================
-- 11: Motivo estructurado para el estado "No Enviado" (id 9)
--     + evento de notificación para sugerencia automática (cron)
-- Fecha: 2026-07-11
-- ============================================================

-- Motivo por el cual el envío quedó en "No Enviado".
-- Valores: sin_datos | no_responde | direccion_invalida | pospuesto_cliente | otro
ALTER TABLE envios
  ADD COLUMN motivo_no_envio VARCHAR(30) NULL DEFAULT NULL AFTER sin_datos_envio,
  ADD COLUMN motivo_no_envio_detalle VARCHAR(500) NULL DEFAULT NULL AFTER motivo_no_envio,
  ADD COLUMN sugerido_no_envio_at DATETIME NULL DEFAULT NULL AFTER motivo_no_envio_detalle;

-- Migración de históricos: los envíos ya en "No Enviado" fueron por falta de
-- datos del cliente (confirmado por gerencia, 2026-07-11).
UPDATE envios SET motivo_no_envio = 'sin_datos'
WHERE estado_envio_id = 9 AND motivo_no_envio IS NULL;

-- Evento de notificación (fase 2: sugerencia del cron al equipo)
INSERT IGNORE INTO notification_events (code, module, name, url_pattern, activo)
VALUES ('envio_sugerir_no_enviado', 'envios',
        'Sugerencia: marcar envío como No Enviado',
        '/modules/envios/ver.php?id={id}', 1);

INSERT INTO notification_rules
  (event_code, recipient_type, role, enabled, priority, via_interna, via_push, via_whatsapp)
SELECT ec, rt, rl, en, pr, vi, vp, vw FROM (
  SELECT 'envio_sugerir_no_enviado' ec, 'ROLE' rt, 'gestor_logistico' rl, 1 en, 2 pr, 1 vi, 1 vp, 0 vw UNION ALL
  SELECT 'envio_sugerir_no_enviado',    'ROLE',    'gerente_operaciones', 1,    2,    1,    0,    0
) t
WHERE NOT EXISTS (
  SELECT 1 FROM notification_rules WHERE event_code = 'envio_sugerir_no_enviado'
);
