-- ============================================================
--  GGI SISTEMA — Script de correcciones críticas
--  Ejecutar en phpMyAdmin sobre la BD: groupglo_sistema
--  Fecha: 2026
--
--  CORRECCIÓN #3: charset latin1 en cotizacion_normas
--  CORRECCIÓN #4: trigger para numeración automática de cotizaciones
-- ============================================================

-- ============================================================
--  #3 — Convertir cotizacion_normas a utf8mb4
--  Corrige: nombres con tildes/eñes se corrompían silenciosamente
-- ============================================================

ALTER TABLE `cotizacion_normas`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Verificación (debe mostrar utf8mb4 en ambas columnas):
-- SHOW CREATE TABLE cotizacion_normas;


-- ============================================================
--  #4 — Trigger para numeración automática de cotizaciones
--  Igual al trigger de clientes (trg_codigo_cliente)
--  Elimina la dependencia de la función PHP generar_numero_cot()
-- ============================================================

-- Asegurar que existe la fila en secuencias para COT del año actual
INSERT INTO `secuencias` (`tipo`, `año`, `ultimo`)
VALUES ('COT', YEAR(NOW()), 0)
ON DUPLICATE KEY UPDATE `tipo` = `tipo`;

-- Crear trigger
DROP TRIGGER IF EXISTS `trg_numero_cotizacion`;

DELIMITER $$
CREATE TRIGGER `trg_numero_cotizacion`
BEFORE INSERT ON `cotizaciones`
FOR EACH ROW
BEGIN
  DECLARE v_ultimo INT;
  DECLARE v_año YEAR;
  SET v_año = YEAR(NOW());

  -- Insertar fila de secuencia si no existe para este año
  INSERT INTO `secuencias` (`tipo`, `año`, `ultimo`)
  VALUES ('COT', v_año, 0)
  ON DUPLICATE KEY UPDATE `tipo` = `tipo`;

  -- Incrementar de forma atómica
  UPDATE `secuencias`
  SET `ultimo` = `ultimo` + 1
  WHERE `tipo` = 'COT' AND `año` = v_año;

  SELECT `ultimo` INTO v_ultimo
  FROM `secuencias`
  WHERE `tipo` = 'COT' AND `año` = v_año;

  -- Solo asignar si viene vacío (permite sobreescribir en migraciones)
  IF NEW.numero IS NULL OR NEW.numero = '' THEN
    SET NEW.numero = CONCAT('COT-', v_año, '-', LPAD(v_ultimo, 3, '0'));
  END IF;
  IF NEW.codigo IS NULL OR NEW.codigo = '' THEN
    SET NEW.codigo = NEW.numero;
  END IF;
END$$
DELIMITER ;

-- Verificación:
-- SHOW TRIGGERS LIKE 'cotizaciones';


-- ============================================================
--  OPCIONAL — Limpiar cotizaciones_detalle si está vacía
--  (nunca se usó en producción, solo era del diseño original)
-- ============================================================
-- Ejecutar solo si la tabla está vacía (verificar primero):
-- SELECT COUNT(*) FROM cotizaciones_detalle;
-- Si devuelve 0, puedes eliminarla con:
-- DROP TABLE IF EXISTS cotizaciones_detalle;


-- ============================================================
--  FIN DEL SCRIPT
-- ============================================================
