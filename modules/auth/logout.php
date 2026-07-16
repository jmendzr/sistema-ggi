<?php
require_once __DIR__ . '/../../core/helpers.php';
session_init();
if (is_logged()) { log_actividad('auth', 'logout', 'Cierre de sesión'); }
$_SESSION = [];
session_destroy();
// Eliminar la cookie del navegador explícitamente (session_destroy solo borra el
// archivo servidor-lado; sin esto la cookie persiste aunque el archivo ya no exista).
$params = session_get_cookie_params();
setcookie(
    SESSION_NAME,
    '',
    time() - 3600,
    $params['path'],
    $params['domain'],
    $params['secure'],
    $params['httponly']
);
header('Location: ' . APP_URL . '/modules/auth/login.php');
exit;
