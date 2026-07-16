<?php
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();
csrf_verify();

$cliente_id = (int)($_POST['cliente_id'] ?? 0);
$mensaje    = trim($_POST['mensaje'] ?? '');
$tipo       = $_POST['tipo'] ?? 'nota';

// Validar redirect para evitar open redirect — solo se permiten URLs internas
$redirect_raw = $_POST['redirect'] ?? '';
$redirect = (str_starts_with($redirect_raw, APP_URL))
    ? $redirect_raw
    : APP_URL . '/modules/clientes/index.php';

$tipos_validos = ['nota','llamada','reunion','seguimiento','alerta'];
if (!$cliente_id || empty($mensaje) || !in_array($tipo, $tipos_validos)) {
    header('Location: ' . $redirect);
    exit;
}

$pdo = Database::get();
$user = auth_user();
$stmt = $pdo->prepare("
    INSERT INTO comunicaciones_cliente (cliente_id, usuario_id, mensaje, tipo)
    VALUES (?, ?, ?, ?)
");
$stmt->execute([$cliente_id, $user['id'], $mensaje, $tipo]);

log_actividad('comunicaciones', 'crear', 'Nota en cliente ID: ' . $cliente_id);

header('Location: ' . $redirect);
exit;
