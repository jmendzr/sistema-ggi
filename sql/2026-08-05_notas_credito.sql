-- Notas de crédito electrónicas (Alegra / SUNAT) emitidas contra una factura.
-- Una factura puede tener 1+ notas de crédito (anulación, corrección, descuento…).

CREATE TABLE IF NOT EXISTS notas_credito_cliente (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    factura_id       INT UNSIGNED NOT NULL,               -- facturas_cliente.id
    cliente_id       INT UNSIGNED NOT NULL,
    cotizacion_id    INT UNSIGNED NULL,
    alegra_id        VARCHAR(30)  NULL,                    -- id de la nota en Alegra
    numero           VARCHAR(50)  NULL,                    -- F100-XXXXXXXX
    tipo             VARCHAR(40)  NOT NULL,                -- código SUNAT cat. 09 (VOID_OPERATION…)
    motivo           VARCHAR(500) NOT NULL,                -- "cause" (motivo de emisión)
    monto_base       DECIMAL(10,2) NULL,
    monto_igv        DECIMAL(10,2) NULL,
    monto_total      DECIMAL(10,2) NULL,
    estado_timbrado  VARCHAR(30)  NULL,                    -- draft | stamped | accepted | error
    alegra_resp      TEXT NULL,
    archivo_path     VARCHAR(500) NULL,
    archivo_nombre   VARCHAR(255) NULL,
    wa_enviado       TINYINT(1) NOT NULL DEFAULT 0,
    wa_enviado_at    TIMESTAMP NULL,
    creado_por       INT UNSIGNED NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_nc_factura (factura_id),
    KEY idx_nc_cliente (cliente_id),
    KEY idx_nc_cot (cotizacion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
