-- Facturación electrónica vía Alegra (SUNAT Perú).
-- Extiende facturas_cliente para guardar las facturas emitidas por API (además
-- de las cargadas manualmente, que siguen igual).
ALTER TABLE facturas_cliente
  ADD COLUMN origen           ENUM('manual','alegra') NOT NULL DEFAULT 'manual' AFTER cotizacion_id,
  ADD COLUMN alegra_id        VARCHAR(30)  NULL AFTER numero_factura,
  ADD COLUMN tipo_factura     ENUM('total','anticipo','saldo') NULL AFTER alegra_id,
  ADD COLUMN operation_type   VARCHAR(40)  NULL AFTER tipo_factura,   -- INTERNAL_SALE | SALE_SUBJECT_TO_DEDUCTION
  ADD COLUMN detraccion_modo  ENUM('cliente','auto','ninguna') NULL AFTER operation_type,
  ADD COLUMN monto_base       DECIMAL(10,2) NULL AFTER detraccion_modo,
  ADD COLUMN monto_igv        DECIMAL(10,2) NULL,
  ADD COLUMN monto_total      DECIMAL(10,2) NULL,
  ADD COLUMN detraccion_monto DECIMAL(10,2) NULL,
  ADD COLUMN estado_timbrado  VARCHAR(30)  NULL,   -- STAMPED_AND_ACCEPTED | draft | error
  ADD COLUMN alegra_resp      TEXT         NULL,
  MODIFY archivo_path   VARCHAR(500) NULL,
  MODIFY archivo_nombre VARCHAR(255) NULL;
