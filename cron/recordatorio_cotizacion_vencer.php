<?php
// ============================================================
//  GGI SISTEMA — Cron: aviso al cliente de COTIZACIÓN POR VENCER
//  Ejecutar 1 vez al día por la mañana: 0 11 * * * (server ≈ 10-11am Lima).
//
//  Envía la plantilla de marketing `ggi_cotizacion_por_vencer` (con botón de
//  respuesta rápida "Quiero aprovecharlo") al cliente cuya cotización ENVIADA
//  está por vencer, en dos toques: 3 días antes y 1 día antes. Una sola vez por
//  toque (columnas aviso_vence_3d_at / aviso_vence_1d_at). Respeta whatsapp_opt_in
//  y solo cotizaciones aún abiertas (estado='enviada').
// ============================================================

if (PHP_SAPI !== 'cli') {
    $token_web = $_GET['token'] ?? '';
    if (!defined('CRON_TOKEN') || $token_web !== CRON_TOKEN) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
}

define('RUNNING_FROM_CRON', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/WhatsAppService.php';

date_default_timezone_set(APP_TIMEZONE);

// Enviar a las 13:00 (1 pm) hora Lima — horario de almuerzo, con el ejecutivo aún
// disponible para atender el "Quiero aprovecharlo". El servidor está en EDT/EST
// (cambia con el DST) y Lima es UTC-5 fijo; el crontab corre a las 13 y 14 del
// servidor y aquí solo continuamos si en Lima es la 1 pm. (--now / ?force la salta.)
$_forzar = (PHP_SAPI === 'cli' && in_array('--now', $argv ?? [], true)) || isset($_GET['force']);
if (!$_forzar && (int)date('H') !== 13) {
    exit("En Lima son las " . date('H:i') . " (se envía a la 13:00); se omite.\n");
}

// WhatsApp debe estar configurado (Meta Cloud API o Kapso). notifyClient maneja
// los fallos por su cuenta, pero evitamos trabajo si claramente no hay credenciales.
$_waToken = defined('META_WA_TOKEN') ? META_WA_TOKEN : (defined('KAPSO_API_KEY') ? KAPSO_API_KEY : '');
if ($_waToken === '') { echo "WhatsApp no configurado.\n"; exit; }

$pdo = Database::get();

// Dos toques: 3 días y 1 día antes del vencimiento.
$toques = [
    ['dias' => 3, 'col' => 'aviso_vence_3d_at'],
    ['dias' => 1, 'col' => 'aviso_vence_1d_at'],
];

$total = 0;
foreach ($toques as $t) {
    $col  = $t['col'];   // nombre de columna fijo (no viene de input)
    $dias = (int)$t['dias'];

    $rows = $pdo->query("
        SELECT c.id, c.numero, c.cliente_id, c.fecha_vencimiento
        FROM cotizaciones c
        JOIN clientes cl ON cl.id = c.cliente_id
        WHERE c.deleted_at IS NULL AND cl.deleted_at IS NULL
          AND c.estado = 'enviada'
          AND c.fecha_vencimiento = DATE_ADD(CURDATE(), INTERVAL {$dias} DAY)
          AND c.{$col} IS NULL
    ")->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $fecha = date('d/m/Y', strtotime($r['fecha_vencimiento']));
        try {
            WhatsAppService::notifyClient((int)$r['cliente_id'], 'cotizacion_por_vencer', [
                'cotizacion_id' => (int)$r['id'],
                'numero'        => $r['numero'],
                'fecha'         => $fecha,
            ]);
        } catch (\Throwable $e) {
            error_log('[recordatorio_cotizacion_vencer] ' . $r['numero'] . ': ' . $e->getMessage());
        }
        // Marcar el toque como enviado (evita duplicados si el cron corre 2 veces el mismo día).
        $pdo->prepare("UPDATE cotizaciones SET {$col} = NOW() WHERE id = ?")->execute([$r['id']]);
        $total++;
        echo "aviso {$dias}d → {$r['numero']} (cliente {$r['cliente_id']}) vence {$fecha}\n";
    }
}

echo "Avisos de cotización por vencer enviados: {$total}\n";
