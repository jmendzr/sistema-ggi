-- ============================================================
-- Módulo: Bonos / Comisiones de Ejecutivos de Ventas
-- ============================================================

CREATE TABLE IF NOT EXISTS bonos_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    norma_iso_id INT NULL COMMENT 'NULL = bono por defecto para cualquier norma',
    monto_bono DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bonos_ejecutivos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ejecutivo_id INT NOT NULL,
    cotizacion_id INT NOT NULL,
    norma_iso_id INT NOT NULL,
    monto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estado ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
    fecha_pago DATE NULL,
    pagado_por INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ejecutivo (ejecutivo_id),
    INDEX idx_cotizacion (cotizacion_id),
    INDEX idx_estado (estado)
);

INSERT IGNORE INTO bonos_config (norma_iso_id, monto_bono) VALUES (NULL, 150.00);
