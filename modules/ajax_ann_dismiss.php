<?php
/**
 * ajax_ann_dismiss.php — Registra que el usuario actual cerró un anuncio.
 *
 * POST ann_hash : string MD5 que identifica el anuncio (texto+tipo+versión)
 *
 * Responde: { ok: true } siempre (silencioso — el usuario ya cerró la barra
 * en la UI, no necesita retroalimentación de este endpoint).
 */
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
session_init();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Requiere sesión activa (no bloquear con require_login para evitar redirección JSON)
if (!is_logged()) {
    echo json_encode(['ok' => false, 'msg' => 'no_session']);
    exit;
}

// CSRF
csrf_verify();

$ann_hash = trim($_POST['ann_hash'] ?? '');

// Validar que sea un hash MD5 válido (32 hex chars)
if (!preg_match('/^[a-f0-9]{32}$/', $ann_hash)) {
    echo json_encode(['ok' => false, 'msg' => 'invalid_hash']);
    exit;
}

$user = auth_user();

try {
    $pdo = Database::get();

    // INSERT IGNORE: si el registro ya existe (dismiss duplicado) no hace nada
    $pdo->prepare("
        INSERT IGNORE INTO anuncios_vistos (usuario_id, ann_hash)
        VALUES (?, ?)
    ")->execute([$user['id'], $ann_hash]);

} catch (Exception $e) {
    // Si la tabla no existe o cualquier error DB → responder ok de todas formas
    // para no interrumpir la UX (la barra ya fue ocultada en el cliente)
}

echo json_encode(['ok' => true]);
