<?php
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();

$id   = (int)($_GET['id'] ?? 0);
$from = $_GET['from'] ?? 'ver';

if (!$id) {
    header('Location: ' . APP_URL . '/modules/cotizaciones/index.php');
    exit;
}

header('Location: ' . APP_URL . '/modules/cotizaciones/crear.php?edit=' . $id . '&from=' . urlencode($from));
exit;
