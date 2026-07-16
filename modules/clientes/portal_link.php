<?php
// Gateway que verifica el estado real del portal antes de redirigir.
// Siempre consulta la BD — nunca depende de token en caché.
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$id   = (int)($_GET['id'] ?? 0);
$json = !empty($_GET['json']);

if (!$id) {
    if ($json) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ID inválido']); exit; }
    header('Location: ' . APP_URL . '/modules/clientes/index.php'); exit;
}

$pdo  = Database::get();
$stmt = $pdo->prepare("SELECT id, razon_social, portal_activo, portal_token FROM clientes WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$c = $stmt->fetch();

$activo = $c && $c['portal_activo'] && $c['portal_token'];

if ($json) {
    header('Content-Type: application/json');
    echo json_encode($activo
        ? ['ok' => true,  'url' => APP_URL . '/portal/?token=' . $c['portal_token']]
        : ['ok' => false, 'msg' => 'El portal de este cliente no está activo.']
    );
    exit;
}

if ($activo) {
    header('Location: ' . APP_URL . '/portal/?token=' . $c['portal_token']);
    exit;
}

// Portal inactivo — mostrar página de error amigable en la nueva pestaña
$nombre = $c ? htmlspecialchars($c['razon_social']) : 'este cliente';
$back   = APP_URL . '/modules/clientes/' . ($c ? 'ver.php?id=' . (int)$c['id'] : 'index.php');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal no disponible</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: var(--s-info-bg);
    min-height: 100svh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    color: #0D0D0F;
  }
  .card {
    background:var(--bg-surface);
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(13,13,15,.07);
    padding: 48px 40px;
    max-width: 420px;
    width: 100%;
    text-align: center;
  }
  .icon {
    width: 56px; height: 56px;
    background:var(--s-warn-bg);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 24px;
  }
  .icon svg { color: #f97316; }
  h1 { font-size: 1.25rem; font-weight: 600; margin-bottom: 10px; }
  p  { font-size: .9rem; color: #64748b; line-height: 1.6; margin-bottom: 28px; }
  .company { font-weight: 600; color: #0D0D0F; }
  a.btn {
    display: inline-flex; align-items: center; gap: 6px;
    background: #0D0D0F; color: #fff;
    text-decoration: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-size: .875rem; font-weight: 500;
    transition: opacity .15s;
  }
  a.btn:hover { opacity: .85; }
</style>
</head>
<body>
<div class="card">
  <div class="icon">
    <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      <line x1="12" y1="9" x2="12" y2="13"/>
      <line x1="12" y1="17" x2="12.01" y2="17"/>
    </svg>
  </div>
  <h1>Portal no disponible</h1>
  <p>El portal de <span class="company"><?= $nombre ?></span> está desactivado.<br>Actívalo desde la pestaña <strong>Portal</strong> en la ficha del cliente.</p>
  <a href="<?= $back ?>" class="btn">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
    Volver a la ficha
  </a>
</div>
</body>
</html>
