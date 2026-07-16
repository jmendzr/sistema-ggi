<?php
// templates/403.php — Página de acceso denegado
// Se incluye desde require_role() cuando el usuario no tiene permisos
require_once __DIR__ . '/../config/config.php';
$user = auth_user();
$back = $_SERVER['HTTP_REFERER'] ?? APP_URL . '/modules/dashboard/index.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Acceso denegado — GGI Sistema</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --azul:       #1A3A8F;
      --azul-light: #e8edf8;
      --verde:      #2DB140;
      --gris-bg:    #f0f2f8;
      --gris-borde: #dde3f0;
      --gris-texto: #6b7a99;
      --gris-dark:  #1a1f36;
      --red:        #ef4444;
      --red-light:  #fee2e2;
    }
    html, body {
      height: 100%;
      font-family: 'Plus Jakarta Sans', sans-serif;
      background: var(--gris-bg);
      color: var(--gris-dark);
    }
    .page {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .card {
      background: #fff;
      border-radius: 20px;
      border: 1px solid var(--gris-borde);
      padding: 48px 40px;
      max-width: 480px;
      width: 100%;
      text-align: center;
      animation: fadeUp 0.4s ease both;
    }

    /* Ícono de escudo con X */
    .icon-wrap {
      width: 72px; height: 72px;
      border-radius: 18px;
      background: var(--red-light);
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 24px;
    }
    .icon-wrap svg { color: var(--red); }

    .code {
      font-family: 'Outfit', sans-serif;
      font-size: 13px;
      font-weight: 600;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: var(--red);
      background: var(--red-light);
      display: inline-block;
      padding: 4px 14px;
      border-radius: 100px;
      margin-bottom: 16px;
    }
    h1 {
      font-family: 'Outfit', sans-serif;
      font-size: 26px;
      font-weight: 700;
      color: var(--gris-dark);
      margin-bottom: 10px;
      letter-spacing: -0.5px;
    }
    .desc {
      font-size: 14px;
      color: var(--gris-texto);
      line-height: 1.7;
      margin-bottom: 32px;
    }
    .desc strong { color: var(--gris-dark); }

    .actions {
      display: flex;
      gap: 10px;
      justify-content: center;
      flex-wrap: wrap;
    }
    .btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 11px 22px;
      border-radius: 10px;
      font-size: 13px; font-weight: 600;
      font-family: 'Plus Jakarta Sans', sans-serif;
      text-decoration: none;
      transition: all 0.15s;
      cursor: pointer; border: none;
    }
    .btn-primary {
      background: var(--azul);
      color: #fff;
    }
    .btn-primary:hover {
      background: #1e4ab0;
      box-shadow: 0 4px 16px rgba(26,58,143,0.25);
    }
    .btn-outline {
      background: #fff;
      color: var(--azul);
      border: 1.5px solid var(--gris-borde);
    }
    .btn-outline:hover {
      border-color: var(--azul);
      background: var(--azul-light);
    }

    /* Info del usuario actual */
    .user-info {
      margin-top: 28px;
      padding-top: 24px;
      border-top: 1px solid var(--gris-borde);
      display: flex;
      align-items: center;
      gap: 10px;
      justify-content: center;
    }
    .user-avatar {
      width: 32px; height: 32px; border-radius: 50%;
      background: var(--azul-light);
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700; color: var(--azul);
      text-transform: uppercase; flex-shrink: 0;
    }
    .user-label { font-size: 12px; color: var(--gris-texto); text-align: left; }
    .user-label strong { display: block; color: var(--gris-dark); font-size: 13px; }
    .user-role {
      display: inline-block;
      margin-left: 6px;
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--azul);
      background: var(--azul-light);
      padding: 1px 7px;
      border-radius: 100px;
      vertical-align: middle;
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(12px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 480px) {
      .card { padding: 36px 24px; }
      h1 { font-size: 22px; }
    }
  </style>
</head>
<body>
<div class="page">
  <div class="card">

    <div class="icon-wrap">
      <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        <line x1="9" y1="9" x2="15" y2="15"/>
        <line x1="15" y1="9" x2="9" y2="15"/>
      </svg>
    </div>

    <div class="code">Error 403</div>

    <h1>Acceso denegado</h1>

    <p class="desc">
      No tienes los permisos necesarios para acceder a esta sección.<br>
      Si crees que esto es un error, contacta al
      <strong>administrador del sistema</strong>.
    </p>

    <div class="actions">
      <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="btn btn-primary">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
          <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
        </svg>
        Ir al Dashboard
      </a>
      <a href="<?= htmlspecialchars($back) ?>" class="btn btn-outline">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
        Volver
      </a>
    </div>

    <?php if ($user): ?>
    <div class="user-info">
      <div class="user-avatar"><?= strtoupper(substr($user['nombre'], 0, 1) . substr($user['apellido'] ?? '', 0, 1)) ?></div>
      <div class="user-label">
        <strong><?= htmlspecialchars($user['nombre'] . ' ' . ($user['apellido'] ?? '')) ?></strong>
        Conectado como
        <span class="user-role"><?= htmlspecialchars(str_replace('_', ' ', $user['rol'])) ?></span>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
