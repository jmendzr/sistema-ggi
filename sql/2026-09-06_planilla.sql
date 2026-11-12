-- Módulo de Planilla: pago de sueldos a trabajadores por período mensual.
CREATE TABLE IF NOT EXISTS planilla (
  id               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  usuario_id       INT UNSIGNED   NOT NULL,
  periodo          CHAR(7)        NOT NULL,               -- 'YYYY-MM'
  sueldo_base      DECIMAL(10,2)  NOT NULL DEFAULT 0.00,  -- snapshot del sueldo al generar
  bonos            DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  descuentos       DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  neto             DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  estado           ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
  metodo_pago      VARCHAR(40)    NULL,
  fecha_pago       DATE           NULL,
  comprobante_path VARCHAR(255)   NULL,
  notas            VARCHAR(300)   NULL,
  created_by       INT UNSIGNED   NULL,
  pagado_por       INT UNSIGNED   NULL,
  created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_usuario_periodo (usuario_id, periodo),
  KEY idx_periodo (periodo),
  KEY idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
