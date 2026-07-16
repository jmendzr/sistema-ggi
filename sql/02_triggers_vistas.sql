DELIMITER //

CREATE TRIGGER `trg_estado_certificado`
BEFORE INSERT ON `certificados`
FOR EACH ROW
BEGIN
  IF NEW.fecha_vencimiento < CURDATE() THEN
    SET NEW.estado = 'vencido';
  ELSEIF NEW.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN
    SET NEW.estado = 'por_vencer';
  ELSE
    SET NEW.estado = 'vigente';
  END IF;

  -- Auto-calcular fechas de auditoría si no se proveen
  IF NEW.fecha_auditoria_1 IS NULL THEN
    SET NEW.fecha_auditoria_1 = DATE_ADD(NEW.fecha_emision, INTERVAL 1 YEAR);
  END IF;
  IF NEW.fecha_auditoria_2 IS NULL THEN
    SET NEW.fecha_auditoria_2 = DATE_ADD(NEW.fecha_emision, INTERVAL 2 YEAR);
  END IF;
END//

CREATE TRIGGER `trg_estado_certificado_update`
BEFORE UPDATE ON `certificados`
FOR EACH ROW
BEGIN
  IF NEW.fecha_vencimiento < CURDATE() THEN
    SET NEW.estado = 'vencido';
  ELSEIF NEW.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN
    SET NEW.estado = 'por_vencer';
  END IF;
END//

-- TRIGGER: generar código interno de cliente automáticamente
CREATE TRIGGER `trg_codigo_cliente`
BEFORE INSERT ON `clientes`
FOR EACH ROW
BEGIN
  DECLARE v_ultimo INT;
  DECLARE v_año YEAR;
  SET v_año = YEAR(NOW());

  UPDATE `secuencias` SET `ultimo` = `ultimo` + 1
  WHERE `tipo` = 'CLIENTE' AND `año` = v_año;

  SELECT `ultimo` INTO v_ultimo
  FROM `secuencias`
  WHERE `tipo` = 'CLIENTE' AND `año` = v_año;

  SET NEW.codigo_interno = CONCAT('GGI-', v_año, '-', LPAD(v_ultimo, 3, '0'));
END//

DELIMITER ;

-- ============================================================
--  VISTAS ÚTILES
-- ============================================================

-- Vista: certificados próximos a vencer (90 días)
CREATE VIEW `v_certificados_por_vencer` AS
SELECT
  c.id,
  cl.codigo_interno,
  cl.razon_social,
  n.codigo AS norma,
  cc.nombre AS casa_certificadora,
  c.numero_certificado,
  c.fecha_vencimiento,
  DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_restantes,
  c.estado
FROM `certificados` c
JOIN `clientes` cl ON cl.id = c.cliente_id
JOIN `normas_iso` n ON n.id = c.norma_iso_id
JOIN `casas_certificadoras` cc ON cc.id = c.casa_certificadora_id
WHERE c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
ORDER BY c.fecha_vencimiento ASC;

-- Vista: auditorías próximas (60 días)
CREATE VIEW `v_auditorias_proximas` AS
SELECT
  a.id,
  cl.codigo_interno,
  cl.razon_social,
  n.codigo AS norma,
  a.tipo,
  a.fecha_programada,
  DATEDIFF(a.fecha_programada, CURDATE()) AS dias_restantes,
  a.resultado
FROM `auditorias` a
JOIN `certificados` c ON c.id = a.certificado_id
JOIN `clientes` cl ON cl.id = c.cliente_id
JOIN `normas_iso` n ON n.id = c.norma_iso_id
WHERE a.fecha_programada BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
  AND a.resultado = 'pendiente'
ORDER BY a.fecha_programada ASC;

-- Vista: envíos sin confirmar recepción
CREATE VIEW `v_envios_pendientes` AS
SELECT
  e.id,
  cl.codigo_interno,
  cl.razon_social,
  ag.nombre AS agencia,
  es.nombre AS estado,
  e.fecha_envio,
  e.codigo_envio,
  DATEDIFF(CURDATE(), e.fecha_envio) AS dias_en_transito
FROM `envios` e
JOIN `clientes` cl ON cl.id = e.cliente_id
JOIN `agencias_envio` ag ON ag.id = e.agencia_envio_id
JOIN `estados_envio` es ON es.id = e.estado_envio_id
WHERE e.fecha_recibido IS NULL
  AND e.fecha_envio IS NOT NULL
ORDER BY e.fecha_envio ASC;

-- ============================================================
--  FIN DEL SCRIPT
-- ============================================================
