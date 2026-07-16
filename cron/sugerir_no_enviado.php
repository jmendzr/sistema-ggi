<?php
// ============================================================
//  GGI SISTEMA — Cron: Sugerir "No Enviado" por falta de datos
//  Ejecutar diariamente: 30 9 * * * php /ruta/cron/sugerir_no_enviado.php
//
//  Cuando un envío sigue "Por preparar" sin datos del cliente y ya se
//  agotaron los 2 recordatorios de WhatsApp (+7 días sin respuesta),
//  notifica al gestor logístico y gerencia de operaciones SUGIRIENDO
//  marcarlo como "No Enviado · Cliente no compartió datos".
//  NUNCA cambia el estado automáticamente: la decisión es del equipo.
//  Se sugiere UNA sola vez por envío (envios.sugerido_no_envio_at).
// ============================================================

define('RUNNING_FROM_CRON', true);
require_once __DIR__ . '/../config/config.php';

if (PHP_SAPI !== 'cli') {
    $token_web = $_GET['token'] ?? '';
    if (!defined('CRON_TOKEN') || $token_web !== CRON_TOKEN) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
}

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/NotificationService.php';

date_default_timezone_set(APP_TIMEZONE);

const DIAS_ESPERA_TRAS_RECORDATORIO = 7;

$pdo = Database::get();
$estado_ini = (int)($pdo->query("SELECT id FROM estados_envio WHERE LOWER(nombre)='por preparar' LIMIT 1")->fetchColumn() ?: 7);

$stmt = $pdo->prepare("
    SELECT e.id, e.cliente_id, e.recordatorio_datos_at, c.razon_social
    FROM envios e
    JOIN clientes c ON c.id = e.cliente_id
    WHERE e.deleted_at IS NULL
      AND e.estado_envio_id = ?
      -- sigue sin datos (mismo criterio que el cron de recordatorios)
      AND (e.agencia_envio_id IS NULL OR e.destinatario IS NULL OR e.destinatario = '')
      -- recordatorios agotados (máximo 2) y +N días sin respuesta
      AND e.recordatorio_datos_n >= 2
      AND e.recordatorio_datos_at IS NOT NULL
      AND e.recordatorio_datos_at <= (NOW() - INTERVAL " . DIAS_ESPERA_TRAS_RECORDATORIO . " DAY)
      -- una sola sugerencia por envío
      AND e.sugerido_no_envio_at IS NULL
    ORDER BY e.id ASC
");
$stmt->execute([$estado_ini]);
$envios = $stmt->fetchAll(\PDO::FETCH_ASSOC);

echo date('[Y-m-d H:i:s]') . " Envíos con datos vencidos a sugerir No Enviado: " . count($envios) . "\n";

$marcar = $pdo->prepare("UPDATE envios SET sugerido_no_envio_at = NOW() WHERE id = ?");
$sugeridos = 0;

foreach ($envios as $e) {
    try {
        $dias = (int)floor((time() - strtotime($e['recordatorio_datos_at'])) / 86400);
        NotificationService::dispatch('envio_sugerir_no_enviado', [
            'id'         => (int)$e['id'],
            'cliente_id' => (int)$e['cliente_id'],
            'title'      => 'Sugerencia: marcar envío como No Enviado',
            'message'    => $e['razon_social'] . ' no compartió sus datos de envío tras 2 recordatorios '
                          . "(último hace {$dias} días). Revisa el envío #{$e['id']} y, si corresponde, "
                          . 'márcalo como "No Enviado · Cliente no compartió datos".',
        ]);
        $marcar->execute([(int)$e['id']]);
        $sugeridos++;
        echo "  → Envío #{$e['id']} ({$e['razon_social']}): sugerencia enviada\n";
    } catch (\Throwable $ex) {
        echo "  ✗ Envío #{$e['id']}: " . $ex->getMessage() . "\n";
        error_log('[sugerir_no_enviado] envío ' . $e['id'] . ': ' . $ex->getMessage());
    }
}

echo date('[Y-m-d H:i:s]') . " Sugerencias enviadas: {$sugeridos}\n";
