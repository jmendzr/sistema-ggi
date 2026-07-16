-- Agregar precio_base a normas_iso
ALTER TABLE `normas_iso`
  ADD COLUMN `precio_base` decimal(10,2) DEFAULT NULL AFTER `descripcion`,
  ADD COLUMN `orden` tinyint UNSIGNED NOT NULL DEFAULT 99 AFTER `precio_base`;

-- Definir orden para las principales (aparecen primero)
UPDATE normas_iso SET orden=1 WHERE codigo LIKE '%9001%';
UPDATE normas_iso SET orden=2 WHERE codigo LIKE '%14001%';
UPDATE normas_iso SET orden=3 WHERE codigo LIKE '%37001%';
UPDATE normas_iso SET orden=4 WHERE codigo LIKE '%45001%';
UPDATE normas_iso SET orden=5 WHERE codigo LIKE '%SA8000%' OR codigo LIKE '%8000%';
UPDATE normas_iso SET orden=6 WHERE codigo LIKE '%13485%';

-- Insertar SA8000 e ISO 13485 si no existen
INSERT IGNORE INTO normas_iso (codigo, nombre, descripcion, activo, orden)
VALUES ('SA8000', 'Responsabilidad Social', 'Estándar de responsabilidad social empresarial', 1, 5);

INSERT IGNORE INTO normas_iso (codigo, nombre, descripcion, activo, orden)
VALUES ('ISO 13485:2016', 'Dispositivos Médicos', 'Sistema de gestión de calidad para dispositivos médicos', 1, 6);
