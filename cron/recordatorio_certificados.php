<?php
// ============================================================
//  GGI SISTEMA — Cron: Recordatorio para GENERAR CERTIFICADOS
//  Ejecutar a las 22:00 (10 pm) hora Lima.
//
//  El Gerente General genera los certificados de forma externa. Este cron le
//  recuerda, la NOCHE ANTERIOR (1 día antes) a las 10 pm, las cotizaciones
//  aceptadas cuya fecha_requerida_certificados es al día siguiente, para que
//  los tenga listos a tiempo. Push + notificación in-app, una sola vez por
//  cotización (columna cotizaciones.alerta_cert_at).
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
require_once __DIR__ . '/../core/PushService.php';

date_default_timezone_set(APP_TIMEZONE);

// Dos recordatorios el día anterior: 19:00 (7 pm) y 22:00 (10 pm) hora Lima.
// El servidor no está en hora Lima (y su offset cambia con el horario de verano
// de EE.UU.), así que el crontab corre en varias horas del servidor y aquí solo
// continuamos si en Lima son las 19 o las 22 h.
// (--now / ?force salta la guarda para pruebas manuales.)
$_HORAS_LIMA = [19, 22];
$_forzar = (PHP_SAPI === 'cli' && in_array('--now', $argv ?? [], true)) || isset($_GET['force']);
if (!$_forzar && !in_array((int)date('H'), $_HORAS_LIMA, true)) {
    exit("En Lima son las " . date('H:i') . " (se dispara solo a las 19:00 y 22:00); se omite.\n");
}

$pdo = Database::get();

$gerentes = $pdo->query("SELECT id FROM usuarios WHERE activo = 1 AND rol = 'gerente_general'")
    ->fetchAll(PDO::FETCH_COLUMN);

// Cotizaciones aceptadas cuya fecha_requerida_certificados es MAÑANA (exactamente
// 1 día antes). NO se incluye "hoy": generar el mismo día que se necesitan no sirve.
// Sin filtro alerta_cert_at para que dispare en AMBAS franjas (19h y 22h); la
// ventana estricta (solo mañana) evita que se repita en otros días.
$stmt = $pdo->query("
    SELECT cot.id, cot.numero, cl.razon_social, cot.fecha_requerida_certificados
    FROM cotizaciones cot
    JOIN clientes cl ON cl.id = cot.cliente_id
    WHERE cot.estado = 'aceptada'
      AND cot.deleted_at IS NULL
      AND cl.deleted_at IS NULL
      AND cot.fecha_requerida_certificados IS NOT NULL
      AND cot.fecha_requerida_certificados = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
");
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$n = 0;
foreach ($pendientes as $cr) {
    $f_fmt  = date('d/m/Y', strtotime($cr['fecha_requerida_certificados']));
    $titulo = "📄 Generar certificados MAÑANA — {$cr['razon_social']}";
    $msg    = "Debes generar los certificados de {$cr['razon_social']} para MAÑANA {$f_fmt}. Propuesta {$cr['numero']}.";
    $url    = APP_URL . '/modules/cotizaciones/ver.php?id=' . $cr['id'];

    foreach ($gerentes as $uid) {
        // Notificación in-app (dedup por usuario+tipo+titulo del día).
        $dup = $pdo->prepare("SELECT id FROM notificaciones WHERE usuario_id=? AND tipo='cert_generar_recordatorio' AND titulo=? AND DATE(created_at)=CURDATE() LIMIT 1");
        $dup->execute([(int)$uid, $titulo]);
        if (!$dup->fetch()) {
            $pdo->prepare("INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url_accion) VALUES (?,?,?,?,?)")
                ->execute([(int)$uid, 'cert_generar_recordatorio', $titulo, $msg, $url]);
        }
        if (class_exists('PushService')) {
            try { PushService::notify((int)$uid, $titulo, $msg, $url, 'cert-gen-' . $cr['id']); } catch (\Throwable $e) {}
        }
    }

    $n++;
    echo "recordatorio cert → propuesta {$cr['numero']} ({$cr['razon_social']}) para {$f_fmt} ({$cuando})\n";
}

echo "Recordatorios de certificados enviados: {$n}\n";
