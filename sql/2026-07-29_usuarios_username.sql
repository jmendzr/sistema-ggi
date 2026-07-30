-- Login por usuario O email: se agrega columna username (única).
ALTER TABLE usuarios ADD COLUMN username VARCHAR(50) NULL AFTER email;
-- Backfill: parte local del email; colisiones resueltas en el script PHP.
CREATE UNIQUE INDEX uq_usuarios_username ON usuarios (username);
