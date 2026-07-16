-- Solo agrega las columnas que faltan (las que no existen aún)
-- Ejecuta línea por línea si alguna ya existe

ALTER TABLE `cotizaciones` ADD COLUMN `auditoria_precio2` decimal(10,2) DEFAULT NULL AFTER `auditoria_precio`;

-- Si subtotal, igv, auditoria_mes, auditoria_anio tampoco existen, ejecuta estas:
-- ALTER TABLE `cotizaciones` ADD COLUMN `subtotal` decimal(10,2) DEFAULT 0.00 AFTER `descuento`;
-- ALTER TABLE `cotizaciones` ADD COLUMN `igv` decimal(10,2) DEFAULT 0.00 AFTER `subtotal`;
-- ALTER TABLE `cotizaciones` ADD COLUMN `auditoria_precio` decimal(10,2) DEFAULT NULL AFTER `igv`;
-- ALTER TABLE `cotizaciones` ADD COLUMN `auditoria_mes` tinyint(2) DEFAULT NULL AFTER `auditoria_precio`;
-- ALTER TABLE `cotizaciones` ADD COLUMN `auditoria_anio` smallint(4) DEFAULT NULL AFTER `auditoria_mes`;
