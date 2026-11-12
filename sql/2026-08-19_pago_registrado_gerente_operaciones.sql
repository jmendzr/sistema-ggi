-- Alinear notificación con permiso: gerente_operaciones también valida pagos
-- (ajax_pago.php acción 'validar' permite gerente_general y gerente_operaciones),
-- pero la regla de 'pago_registrado' solo notificaba a gerente_general.
-- Se agrega gerente_operaciones (interna + push), evitando duplicar si ya existe.
INSERT INTO notification_rules
  (event_code, recipient_type, role, user_id, enabled, priority, via_interna, via_push, via_whatsapp)
SELECT 'pago_registrado', 'ROLE', 'gerente_operaciones', NULL, 1, 1, 1, 1, 0
WHERE NOT EXISTS (
  SELECT 1 FROM notification_rules
  WHERE event_code='pago_registrado' AND recipient_type='ROLE' AND role='gerente_operaciones'
);
