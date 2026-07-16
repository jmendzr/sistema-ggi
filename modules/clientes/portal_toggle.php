<?php
// modules/clientes/portal_toggle.php
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();
csrf_verify();

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
           && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$pdo        = Database::get();
$cliente_id = (int)($_POST['cliente_id'] ?? 0);
$accion     = $_POST['accion'] ?? '';

if (!$cliente_id || !in_array($accion, ['activar','desactivar','regenerar'])) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos.', 'csrf' => csrf_token()]);
        exit;
    }
    header('Location: ' . APP_URL . '/modules/clientes/index.php');
    exit;
}

// Verificar que el cliente existe
$stmt = $pdo->prepare("SELECT id, portal_token, portal_activo FROM clientes WHERE id = ?");
$stmt->execute([$cliente_id]);
$c = $stmt->fetch();
if (!$c) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Cliente no encontrado.', 'csrf' => csrf_token()]);
        exit;
    }
    header('Location: ' . APP_URL . '/modules/clientes/index.php');
    exit;
}

$token         = $c['portal_token'];
$portal_activo = (int)$c['portal_activo'];
$portal_url    = null;

switch ($accion) {
    case 'activar':
    case 'regenerar':
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE clientes SET portal_token = ?, portal_activo = 1 WHERE id = ?")
            ->execute([$token, $cliente_id]);
        $msg           = $accion === 'activar' ? 'Portal activado correctamente.' : 'Enlace regenerado correctamente.';
        $portal_activo = 1;
        $portal_url    = APP_URL . '/portal/?token=' . $token;
        log_actividad('clientes', 'portal_' . $accion, "Cliente ID: $cliente_id");
        break;

    case 'desactivar':
        $pdo->prepare("UPDATE clientes SET portal_activo = 0 WHERE id = ?")
            ->execute([$cliente_id]);
        $msg           = 'Portal desactivado correctamente.';
        $portal_activo = 0;
        $portal_url    = null;
        log_actividad('clientes', 'portal_desactivar', "Cliente ID: $cliente_id");
        break;
}

if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'            => true,
        'msg'           => $msg,
        'portal_activo' => $portal_activo,
        'portal_url'    => $portal_url,
        'portal_token'  => $token,
        'csrf'          => csrf_token(),
    ]);
    exit;
}

header('Location: ' . APP_URL . '/modules/clientes/editar.php?id=' . $cliente_id . '&msg=' . urlencode($msg));
exit;
