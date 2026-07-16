-- ============================================================
--  GGI SISTEMA — Roles y Permisos
--  Ejecutar UNA sola vez para crear las tablas e insertar datos
--  iniciales que replican exactamente los permisos hardcodeados.
-- ============================================================

-- 1. Definición de roles con metadatos
CREATE TABLE IF NOT EXISTS `roles` (
    `nombre`      VARCHAR(50)  NOT NULL,
    `label`       VARCHAR(100) NOT NULL,
    `descripcion` TEXT,
    `color`       VARCHAR(7)   NOT NULL DEFAULT '#1A3A8F',
    `orden`       TINYINT UNSIGNED NOT NULL DEFAULT 99,
    PRIMARY KEY (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Permisos por módulo para cada rol
CREATE TABLE IF NOT EXISTS `rol_permisos` (
    `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rol`    VARCHAR(50) NOT NULL,
    `modulo` VARCHAR(50) NOT NULL,
    `activo` TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rol_modulo` (`rol`, `modulo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Datos iniciales: roles ───────────────────────────────────
INSERT IGNORE INTO `roles` (`nombre`, `label`, `descripcion`, `color`, `orden`) VALUES
('gerente_general',     'Gerente General',       'Acceso completo al sistema. Sus permisos no son modificables.',                                          '#1A3A8F', 1),
('gerente_operaciones', 'Gerente de Operaciones','Gestión operativa: clientes, certificados, envíos, auditorías y reportes.',                              '#2563EB', 2),
('ejecutivo_ventas',    'Ejecutivo de Ventas',   'Gestión comercial: cotizaciones, clientes, bonos y metas de ventas.',                                    '#2DB140', 3),
('gestor_logistico',    'Gestor Logístico',      'Operaciones de envío y seguimiento de certificados.',                                                    '#F59E0B', 4),
('delivery',            'Delivery',              'Acceso mínimo: gestión de envíos asignados y consulta básica de clientes.',                              '#EF4444', 5);

-- ── Datos iniciales: permisos (replica permisos hardcodeados) ─
-- gerente_operaciones
INSERT IGNORE INTO `rol_permisos` (`rol`, `modulo`, `activo`) VALUES
('gerente_operaciones', 'clientes',       1),
('gerente_operaciones', 'certificados',   1),
('gerente_operaciones', 'envios',         1),
('gerente_operaciones', 'auditorias',     1),
('gerente_operaciones', 'documentos',     1),
('gerente_operaciones', 'reportes',       1),
('gerente_operaciones', 'comunicaciones', 1),
('gerente_operaciones', 'notificaciones', 1),
('gerente_operaciones', 'bonos',          1),
('gerente_operaciones', 'metas',          1),
('gerente_operaciones', 'pagos',          1),
('gerente_operaciones', 'whatsapp',       1),
('gerente_operaciones', 'mantenimiento',  1);

-- ejecutivo_ventas
INSERT IGNORE INTO `rol_permisos` (`rol`, `modulo`, `activo`) VALUES
('ejecutivo_ventas', 'clientes',       1),
('ejecutivo_ventas', 'certificados',   1),
('ejecutivo_ventas', 'cotizaciones',   1),
('ejecutivo_ventas', 'envios',         1),
('ejecutivo_ventas', 'documentos',     1),
('ejecutivo_ventas', 'comunicaciones', 1),
('ejecutivo_ventas', 'notificaciones', 1),
('ejecutivo_ventas', 'bonos',          1),
('ejecutivo_ventas', 'metas',          1),
('ejecutivo_ventas', 'pagos',          1);

-- gestor_logistico
INSERT IGNORE INTO `rol_permisos` (`rol`, `modulo`, `activo`) VALUES
('gestor_logistico', 'envios',         1),
('gestor_logistico', 'certificados',   1),
('gestor_logistico', 'notificaciones', 1);

-- delivery
INSERT IGNORE INTO `rol_permisos` (`rol`, `modulo`, `activo`) VALUES
('delivery', 'envios',         1),
('delivery', 'clientes_ver',   1),
('delivery', 'notificaciones', 1);
