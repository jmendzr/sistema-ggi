<?php
/**
 * cron/sync_tracking_olva.php
 * Sincronización de estados Olva, Shalom y Serpost para todos los envíos activos.
 * Cada avance de estado notifica automáticamente al cliente (WhatsApp).
 * Ejecutar: php /path/to/cron/sync_tracking_olva.php
 * Crontab:  0 * * * * php /home/groupglo/.../cron/sync_tracking_olva.php >> /tmp/tracking_sync.log 2>&1
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/OlvaTrackingService.php';
require_once __DIR__ . '/../core/ShalomTrackingService.php';
require_once __DIR__ . '/../core/SerpostTrackingService.php';
require_once __DIR__ . '/../core/envio_notificar.php';

$pdo = Database::get();

// Estados terminales (no sincronizar)
$stmt_terminal  = $pdo->query("SELECT id FROM estados_envio WHERE LOWER(nombre) IN ('entregado','devuelto')");
$ids_terminales = array_column($stmt_terminal->fetchAll(), 'id');
$ph_terminal    = implode(',', array_fill(0, count($ids_terminales), '?'));

$total_olva     = 0;
$total_shalom   = 0;
$total_serpost  = 0;
$avanzados      = 0;
$errores        = 0;

// ── Olva ──────────────────────────────────────────────────────────────────────
$stmt_olva = $pdo->prepare("
    SELECT e.id, e.codigo_envio
    FROM envios e
    JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    WHERE e.deleted_at IS NULL
      AND LOWER(ag.nombre) LIKE '%olva%'
      AND e.codigo_envio IS NOT NULL AND e.codigo_envio != ''
      AND e.estado_envio_id NOT IN ($ph_terminal)
    ORDER BY e.updated_at ASC
");
$stmt_olva->execute($ids_terminales);
$envios_olva  = $stmt_olva->fetchAll();
$total_olva   = count($envios_olva);

echo date('[Y-m-d H:i:s]') . " [Olva] Envíos a revisar: $total_olva\n";

foreach ($envios_olva as $env) {
    try {
        $nuevo = OlvaTrackingService::sincronizar((int)$env['id'], $pdo);
        if ($nuevo !== null) {
            $avanzados++;
            gg_envio_notificar_estado($pdo, (int)$env['id'], (int)$nuevo, null); // notifica al cliente
            echo date('[H:i:s]') . " [Olva] Envío #{$env['id']} ({$env['codigo_envio']}) → estado_id=$nuevo\n";
        }
        usleep(300000);
    } catch (Throwable $e) {
        $errores++;
        echo date('[H:i:s]') . " [Olva] ERROR envío #{$env['id']}: " . $e->getMessage() . "\n";
    }
}

// ── Shalom ────────────────────────────────────────────────────────────────────
$stmt_shalom = $pdo->prepare("
    SELECT e.id, e.numero_orden, e.codigo_envio
    FROM envios e
    JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    WHERE e.deleted_at IS NULL
      AND LOWER(ag.nombre) LIKE '%shalom%'
      AND e.numero_orden IS NOT NULL AND e.numero_orden != ''
      AND e.codigo_envio IS NOT NULL AND e.codigo_envio != ''
      AND e.estado_envio_id NOT IN ($ph_terminal)
    ORDER BY e.updated_at ASC
");
$stmt_shalom->execute($ids_terminales);
$envios_shalom = $stmt_shalom->fetchAll();
$total_shalom  = count($envios_shalom);

echo date('[Y-m-d H:i:s]') . " [Shalom] Envíos a revisar: $total_shalom\n";

foreach ($envios_shalom as $env) {
    try {
        $nuevo = ShalomTrackingService::sincronizar((int)$env['id'], $pdo);
        if ($nuevo !== null) {
            $avanzados++;
            gg_envio_notificar_estado($pdo, (int)$env['id'], (int)$nuevo, null); // notifica al cliente
            echo date('[H:i:s]') . " [Shalom] Envío #{$env['id']} ({$env['numero_orden']}) → estado_id=$nuevo\n";
        }
        usleep(400000); // 400ms entre llamadas Shalom (2 peticiones por envío)
    } catch (Throwable $e) {
        $errores++;
        echo date('[H:i:s]') . " [Shalom] ERROR envío #{$env['id']}: " . $e->getMessage() . "\n";
    }
}

// ── Serpost ─────────────────────────────────────────────────────────────────────
$stmt_serpost = $pdo->prepare("
    SELECT e.id, e.codigo_envio
    FROM envios e
    JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    WHERE e.deleted_at IS NULL
      AND LOWER(ag.nombre) LIKE '%serpost%'
      AND e.codigo_envio IS NOT NULL AND e.codigo_envio != ''
      AND e.estado_envio_id NOT IN ($ph_terminal)
    ORDER BY e.updated_at ASC
");
$stmt_serpost->execute($ids_terminales);
$envios_serpost = $stmt_serpost->fetchAll();
$total_serpost  = count($envios_serpost);

echo date('[Y-m-d H:i:s]') . " [Serpost] Envíos a revisar: $total_serpost\n";

foreach ($envios_serpost as $env) {
    try {
        $nuevo = SerpostTrackingService::sincronizar((int)$env['id'], $pdo);
        if ($nuevo !== null) {
            $avanzados++;
            gg_envio_notificar_estado($pdo, (int)$env['id'], (int)$nuevo, null); // notifica al cliente
            echo date('[H:i:s]') . " [Serpost] Envío #{$env['id']} ({$env['codigo_envio']}) → estado_id=$nuevo\n";
        }
        usleep(300000);
    } catch (Throwable $e) {
        $errores++;
        echo date('[H:i:s]') . " [Serpost] ERROR envío #{$env['id']}: " . $e->getMessage() . "\n";
    }
}

$total = $total_olva + $total_shalom + $total_serpost;
echo date('[Y-m-d H:i:s]') . " Sync completado. Total: $total | Avanzados: $avanzados | Errores: $errores\n";
