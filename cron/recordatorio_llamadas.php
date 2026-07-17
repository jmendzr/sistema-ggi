<?php
// ============================================================
//  GGI SISTEMA — Cron: Recordatorio de llamadas programadas
//  Ejecutar CADA 5 MINUTOS: */5 * * * * php /ruta/cron/recordatorio_llamadas.php
//
//  Dos avisos por llamada, SOLO al usuario asignado (push + interna):
//   1) Aviso previo:  faltan <= aviso_min minutos  → "Llamar a X en N min"
//   2) A la hora:     llegó fecha_hora sin marcar  → "Es hora de llamar a X"
//  Cada aviso se envía una sola vez (aviso_previo_at / aviso_hora_at).
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
require_once __DIR__ . '/../core/PushService.php';

date_default_timezone_set(APP_TIMEZONE);

$pdo = Database::get();

$ins_notif = $pdo->prepare("
    INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url_accion, priority, reference_id, event_code)
    VALUES (?, 'llamada_programada', ?, ?, ?, 2, ?, 'llamada_programada')
");

function _avisar(PDO $pdo, PDOStatement $ins_notif, array $ll, string $titulo, string $cuerpo): void {
    $url = APP_URL . '/modules/clientes/ver.php?id=' . (int)$ll['cliente_id'];
    // Interna (campana) — reference_id = cliente para agrupar
    $ins_notif->execute([(int)$ll['usuario_id'], $titulo, $cuerpo, $url, (int)$ll['cliente_id']]);
    // Push al dispositivo del asignado (tag única por llamada+tipo evita duplicados visuales)
    try {
        PushService::notify((int)$ll['usuario_id'], $titulo, $cuerpo, $url, 'llamada-' . $ll['id']);
    } catch (\Throwable $e) {
        error_log('[recordatorio_llamadas] push llamada ' . $ll['id'] . ': ' . $e->getMessage());
    }
}

$hoy_fmt = fn(string $dt) => date('H:i', strtotime($dt));

// ── 1) Aviso previo: entramos en la ventana [fecha_hora - aviso_min, fecha_hora) ──
$prev = $pdo->query("
    SELECT ll.*, c.razon_social, c.contacto_telefono, c.horario_contacto
    FROM llamadas_programadas ll
    JOIN clientes c ON c.id = ll.cliente_id
    WHERE ll.estado = 'pendiente'
      AND ll.aviso_previo_at IS NULL
      AND NOW() >= (ll.fecha_hora - INTERVAL ll.aviso_min MINUTE)
      AND NOW() <  ll.fecha_hora
")->fetchAll(\PDO::FETCH_ASSOC);

$n1 = 0;
foreach ($prev as $ll) {
    $min = max(1, (int)ceil((strtotime($ll['fecha_hora']) - time()) / 60));
    $tel = $ll['contacto_telefono'] ? ' · ' . $ll['contacto_telefono'] : '';
    _avisar($pdo, $ins_notif, $ll,
        "📞 Llamar a {$ll['razon_social']} en {$min} min",
        'Acordado para las ' . $hoy_fmt($ll['fecha_hora']) . $tel
        . ($ll['nota'] ? ' — ' . $ll['nota'] : ''));
    $pdo->prepare("UPDATE llamadas_programadas SET aviso_previo_at = NOW(), estado = 'notificada' WHERE id = ?")
        ->execute([(int)$ll['id']]);
    $n1++;
}

// ── 2) A la hora exacta: sigue sin marcarse realizada/cancelada ──
$ahora = $pdo->query("
    SELECT ll.*, c.razon_social, c.contacto_telefono, c.horario_contacto
    FROM llamadas_programadas ll
    JOIN clientes c ON c.id = ll.cliente_id
    WHERE ll.estado IN ('pendiente','notificada')
      AND ll.aviso_hora_at IS NULL
      AND NOW() >= ll.fecha_hora
")->fetchAll(\PDO::FETCH_ASSOC);

$n2 = 0;
foreach ($ahora as $ll) {
    $tel = $ll['contacto_telefono'] ? ' · ' . $ll['contacto_telefono'] : '';
    _avisar($pdo, $ins_notif, $ll,
        "📞 Es hora de llamar a {$ll['razon_social']}",
        'Acordado para las ' . $hoy_fmt($ll['fecha_hora']) . $tel
        . ($ll['nota'] ? ' — ' . $ll['nota'] : ''));
    $pdo->prepare("UPDATE llamadas_programadas SET aviso_hora_at = NOW(), estado = 'notificada' WHERE id = ?")
        ->execute([(int)$ll['id']]);
    $n2++;
}

echo date('[Y-m-d H:i:s]') . " Avisos previos: {$n1} · A la hora: {$n2}\n";
