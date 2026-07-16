-- 2026-07-10 — Flujo "desistida": empresa acepta la propuesta y luego desiste.
-- Nuevo estado final 'desistida' + trazabilidad del desistimiento.
ALTER TABLE cotizaciones
  MODIFY estado ENUM('borrador','enviada','aceptada','rechazada','vencida','desistida') NOT NULL DEFAULT 'borrador',
  ADD COLUMN motivo_desistimiento TEXT NULL AFTER motivo_rechazo,
  ADD COLUMN desistida_at DATETIME NULL AFTER motivo_desistimiento,
  ADD COLUMN desistida_by INT(10) UNSIGNED NULL AFTER desistida_at;

-- Evento de notificación interna/push (gerentes + ejecutivo de la propuesta)
INSERT INTO notification_events (code, module, name, url_pattern, activo)
VALUES ('propuesta_desistida', 'cotizaciones', 'Propuesta desistida', '/modules/cotizaciones/ver.php?id={id}', 1);

INSERT INTO notification_rules (event_code, recipient_type, role, enabled, priority, via_interna, via_push, via_whatsapp) VALUES
  ('propuesta_desistida', 'ROLE', 'gerente_general',     1, 1, 1, 1, 0),
  ('propuesta_desistida', 'ROLE', 'gerente_operaciones', 1, 1, 1, 1, 0),
  ('propuesta_desistida', 'PROPOSAL_OWNER', '',          1, 1, 1, 1, 0);
