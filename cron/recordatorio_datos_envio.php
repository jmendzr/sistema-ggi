<?php
/**
 * cron/recordatorio_datos_envio.php
 * Recordatorio por WhatsApp al cliente para que cargue los datos de su envío.
 *
 * Condiciones (todas):
 *   - Envío en "Por preparar" y SIN datos cargados (falta agencia o destinatario).
 *   - La cotización del envío está PAGADA POR COMPLETO (todos los pagos validados).
 *   - Cliente con WhatsApp opt-in, teléfono y portal (token) activo.
 *
 * Cadencia: 1er recordatorio al detectar el pago total; 2do a los ≥2 días si sigue
 * pendiente. Máximo 2 (recordatorio_datos_n). Incluye el enlace del portal (con token).
 *
 * Crontab: 0 10 * * * php /home/groupglo/.../cron/recordatorio_datos_envio.php >> /tmp/recordatorio_envios.log 2>&1
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/NotificationService.php';

$pdo = Database::get();
$estado_ini = (int)($pdo->query("SELECT id FROM estados_envio WHERE LOWER(nombre)='por preparar' LIMIT 1")->fetchColumn() ?: 7);

$stmt = $pdo->prepare("
    SELECT e.id, e.cliente_id, e.recordatorio_datos_n, c.razon_social, c.portal_token
    FROM envios e
    JOIN clientes c ON c.id = e.cliente_id
    WHERE e.deleted_at IS NULL
      AND e.estado_envio_id = ?
      AND e.sin_datos_envio = 0
      AND (e.agencia_envio_id IS NULL OR e.destinatario IS NULL OR e.destinatario = '')
      AND e.cotizacion_id IS NOT NULL
      AND c.whatsapp_opt_in = 1
      AND c.contacto_telefono IS NOT NULL AND c.contacto_telefono != ''
      AND c.portal_token IS NOT NULL AND c.portal_token != ''
      -- pago total: existe al menos un pago y NINGUNO sin validar
      AND (SELECT COUNT(*) FROM pagos_cliente pc WHERE pc.cotizacion_id=e.cotizacion_id AND pc.deleted_at IS NULL) > 0
      AND (SELECT COUNT(*) FROM pagos_cliente pc WHERE pc.cotizacion_id=e.cotizacion_id AND pc.deleted_at IS NULL AND pc.estado <> 'validado') = 0
      -- cadencia: #1 si nunca; #2 si ya se envió 1 y pasaron ≥2 días
      AND (
            e.recordatorio_datos_n = 0
            OR (e.recordatorio_datos_n = 1 AND (e.recordatorio_datos_at IS NULL OR e.recordatorio_datos_at <= (NOW() - INTERVAL 2 DAY)))
          )
    ORDER BY e.id ASC
");
$stmt->execute([$estado_ini]);
$envios = $stmt->fetchAll(\PDO::FETCH_ASSOC);

echo date('[Y-m-d H:i:s]') . " Envíos a recordar (pago total + datos pendientes): " . count($envios) . "\n";

$marcar = $pdo->prepare("UPDATE envios SET recordatorio_datos_n = recordatorio_datos_n + 1, recordatorio_datos_at = NOW() WHERE id = ?");
$enviados = 0;

foreach ($envios as $e) {
    try {
        $nro = (int)$e['recordatorio_datos_n'] + 1; // 1 o 2
        // 1er aviso: invitación amable; 2do (último): recordatorio final.
        $evento = $nro >= 2 ? 'envio_datos_pendientes_final' : 'envio_datos_pendientes';
        NotificationService::dispatch($evento, [
            'id'           => (int)$e['id'],
            'cliente_id'   => (int)$e['cliente_id'],
            'portal_token' => $e['portal_token'],   // sufijo del botón URL "Abrir mi portal"
            'portal_link'  => APP_URL . '/portal/?token=' . $e['portal_token'], // fallback si algún día se usa en texto
            'title'        => 'Datos de envío pendientes',
            'message'      => "Recordatorio ({$nro}/2) para cargar datos del envío #{$e['id']}.",
        ]);
        $marcar->execute([$e['id']]);
        $enviados++;
        echo date('[H:i:s]') . " Recordatorio {$nro}/2 — envío #{$e['id']} ({$e['razon_social']})\n";
        usleep(400000);
    } catch (\Throwable $ex) {
        echo date('[H:i:s]') . " ERROR envío #{$e['id']}: " . $ex->getMessage() . "\n";
    }
}

echo date('[Y-m-d H:i:s]') . " Recordatorios enviados: $enviados\n";
