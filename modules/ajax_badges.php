<?php
/**
 * ajax_badges.php — Conteos de badges para polling en tiempo real
 * Devuelve JSON: { ok, notif, pagos }
 */
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
session_init();

if (!is_logged()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'expired' => true]);
    exit;
}

$user = auth_user();
$pdo  = Database::get();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$notif = 0;
$pagos = 0;
$cot   = 0;

// ── Notificaciones no leídas ──
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leida=0");
    $s->execute([$user['id']]);
    $notif = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── Badge de pagos según rol ──
try {
    if ($user['rol'] === 'ejecutivo_ventas') {
        $_pag_sess_key = 'pag_last_visit_' . $user['id'];
        if (isset($_SESSION[$_pag_sess_key])) {
            $last = $_SESSION[$_pag_sess_key];
        } else {
            $row = $pdo->prepare('SELECT ultima_visita_pagos FROM usuarios WHERE id = ?');
            $row->execute([$user['id']]);
            $dbval = $row->fetchColumn();
            $last = $dbval ?: date('Y-m-d H:i:s', strtotime('-30 days'));
        }
        $s = $pdo->prepare("
            SELECT COUNT(*) FROM pagos_cliente p
            JOIN cotizaciones cot ON cot.id = p.cotizacion_id AND cot.deleted_at IS NULL
            JOIN clientes     c   ON c.id   = p.cliente_id AND c.deleted_at IS NULL
            WHERE p.deleted_at IS NULL
              AND p.estado IN ('validado','rechazado')
              AND p.fecha_validacion > ?
              AND COALESCE(cot.ejecutivo_id, c.ejecutivo_id) = ?
        ");
        $s->execute([$last, $user['id']]);
        $pagos = (int)$s->fetchColumn();
    } else {
        $pagos = (int)$pdo->query(
            "SELECT COUNT(*) FROM pagos_cliente WHERE deleted_at IS NULL AND estado='pendiente_validacion'"
        )->fetchColumn();
    }
} catch (Exception $e) {}

// ── Badge propuestas próximas a vencer (≤ 48 h) ─────────────────────────────
// El valor ultima_visita_propuestas se cachea en sesión para evitar 1 query
// extra a la tabla usuarios en CADA poll (igual que el patrón de pagos arriba).
try {
    $_uv_sess_key = 'uv_propuestas_' . $user['id'];
    if (isset($_SESSION[$_uv_sess_key])) {
        $uv_val = $_SESSION[$_uv_sess_key];
    } else {
        $uv_row = $pdo->prepare("SELECT ultima_visita_propuestas FROM usuarios WHERE id = ?");
        $uv_row->execute([$user['id']]);
        $uv_val = $uv_row->fetchColumn() ?: null;
        $_SESSION[$_uv_sess_key] = $uv_val;
    }
    $ya_visito_hoy = ($uv_val && $uv_val === date('Y-m-d'));

    // Cuando el usuario visita propuestas, index.php actualiza la columna y debe
    // invalidar la clave de sesión para que el próximo poll lea el valor fresco.
    // index.php ya hace el UPDATE; aquí sólo reseteamos la cache de sesión
    // si la fecha guardada es de hoy (para que el badge se apague de inmediato).
    if ($ya_visito_hoy && $_SESSION[$_uv_sess_key] !== date('Y-m-d')) {
        $_SESSION[$_uv_sess_key] = date('Y-m-d');
    }

    if (!$ya_visito_hoy) {
        if ($user['rol'] === 'ejecutivo_ventas') {
            $sc = $pdo->prepare("
                SELECT COUNT(*) FROM cotizaciones
                WHERE deleted_at IS NULL
                  AND ejecutivo_id = ?
                  AND estado IN ('borrador','enviada')
                  AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
            ");
            $sc->execute([$user['id']]);
            $cot = (int)$sc->fetchColumn();
        } else {
            $cot = (int)$pdo->query("
                SELECT COUNT(*) FROM cotizaciones
                WHERE deleted_at IS NULL
                  AND estado IN ('borrador','enviada')
                  AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
            ")->fetchColumn();
        }
    }
} catch (Exception $e) {}

// Liberar el lock de sesión: todas las lecturas/escrituras de sesión ya terminaron.
session_write_close();

$payload = ['ok' => true, 'notif' => $notif, 'pagos' => $pagos, 'cot' => $cot];

// ETag: si los valores no cambiaron desde el último poll, devolver 304 sin body.
// El navegador recibe la misma respuesta lógica sin procesar JSON ni actualizar el DOM.
$etag = '"' . md5($notif . ',' . $pagos . ',' . $cot) . '"';
header('ETag: ' . $etag);
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
    http_response_code(304);
    exit;
}

echo json_encode($payload);
