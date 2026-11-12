-- Auto-respuesta fuera de horario (chats conversacionales Frank/Andrés).
ALTER TABLE wa_conversaciones ADD COLUMN auto_fuera_at DATETIME NULL AFTER lead_caliente_at;

CREATE TABLE IF NOT EXISTS feriados (
    fecha  DATE NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    PRIMARY KEY (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
