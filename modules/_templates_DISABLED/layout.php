<?php
// templates/layout.php — v2.1
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// Uso: include con $page_title, $active_menu definidos antes
require_once __DIR__ . '/../core/helpers.php';
session_init();
require_login();
$user = auth_user();
// Contador de notificaciones no leídas para el header
$_notif_count = 0;
try {
    $_pdo_layout = Database::get();
    $_notif_count = (int)$_pdo_layout->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leida=0")
        ->execute([$user['id']]) ? $_pdo_layout->query("SELECT COUNT(*) FROM notificaciones WHERE usuario_id={$user['id']} AND leida=0")->fetchColumn() : 0;
} catch (Exception $e) { $_notif_count = 0; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <title><?= sanitize($page_title ?? 'GGI Sistema') ?> — GGI</title>
  <meta http-equiv="Cache-Control" content="no-cache, must-revalidate">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
  <!-- Aplicar tema antes del render para evitar parpadeo -->
  <script>
    (function(){
      var t = localStorage.getItem('ggi_theme');
      if (t === 'dark') document.documentElement.setAttribute('data-theme','dark');
    })();
  </script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    /* ── Tema claro (default) ── */
    :root {
      --azul:        #1A3A8F;
      --azul-dark:   #0d1f5c;
      --azul-mid:    #2249b3;
      --azul-light:  #e8effe;
      --verde:       #2DB140;
      --verde-dark:  #229133;
      --verde-light: #edfbf0;
      --gris-bg:     #f4f6fc;
      --gris-card:   #ffffff;
      --gris-borde:  #dde4f5;
      --gris-texto:  #5a6a8a;
      --gris-dark:   #18213a;
      --header-bg:   #ffffff;
      --header-borde:#dde4f5;
      --shadow-card: 0 1px 4px rgba(26,58,143,.07), 0 1px 2px rgba(26,58,143,.05);
      --sidebar-w:   240px;
      --header-h:    60px;
      --amber:       #f59e0b;
      --red:         #ef4444;
    }

    /* ── Tema oscuro ── */
    [data-theme="dark"] {
      --azul-light:   rgba(77,124,254,0.15);
      --verde-light:  rgba(45,177,64,0.12);
      --gris-bg:      #0f172a;
      --gris-card:    #1e293b;
      --gris-borde:   #2d3f5a;
      --gris-texto:   #94a3b8;
      --gris-dark:    #e2e8f0;
      --sidebar-bg:   #060d1a;
      --header-bg:    #1e293b;
      --header-borde: #2d3f5a;
    }
    [data-theme="dark"] .btn-outline       { background: var(--gris-card); color: #93c5fd; border-color: var(--gris-borde); }
    [data-theme="dark"] .btn-outline:hover { background: rgba(77,124,254,0.12); border-color: #4d7cfe; color: #93c5fd; }
    [data-theme="dark"] .notif-dot         { border-color: var(--header-bg); }
    [data-theme="dark"] .header-title      { color: #e2e8f0; }
    [data-theme="dark"] .page-header h1    { color: #93c5fd; }
    [data-theme="dark"] .card              { background: var(--gris-card); border-color: var(--gris-borde); }
    [data-theme="dark"] .btn-toggle-desktop{ background: var(--gris-card); border-color: var(--gris-borde); }
    [data-theme="dark"] .btn-toggle-desktop:hover { background: rgba(77,124,254,0.15); color: #93c5fd; }

    html, body { height: 100%; font-family: 'Inter', sans-serif; background: var(--gris-bg); color: var(--gris-dark); font-size: 14px; transition: background 0.2s, color 0.2s; }

    /* ── Sidebar ── */
    .sidebar {
      position: fixed; top: 0; left: 0;
      width: var(--sidebar-w); height: 100vh;
      background: linear-gradient(170deg, #1a3a8f 0%, #0d1f5c 100%);
      display: flex; flex-direction: column;
      z-index: 100; overflow-y: auto;
    }
    [data-theme="dark"] .sidebar {
      background: linear-gradient(170deg, #0d1833 0%, #060d1a 100%);
    }
    .sidebar-logo {
      padding: 20px 20px 16px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      display: flex; align-items: center; gap: 10px;
    }
    .sidebar-logo img { max-height: 36px; width: auto; max-width: 130px; object-fit: contain; flex-shrink: 0; display: block; }
    .sidebar-logo-text { line-height: 1.2; }
    .sidebar-logo-text strong {
      font-family: 'Outfit', sans-serif; font-size: 14px;
      font-weight: 700; color: #fff; display: block;
    }
    .sidebar-logo-text span { font-size: 10px; color: rgba(255,255,255,0.45); letter-spacing: 0.5px; text-transform: uppercase; }

    .sidebar-section { padding: 16px 12px 4px; }
    .sidebar-section-label { font-size: 10px; font-weight: 600; color: rgba(255,255,255,0.3); letter-spacing: 1px; text-transform: uppercase; padding: 0 8px; margin-bottom: 4px; }

    .nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 12px; border-radius: 8px;
      color: rgba(255,255,255,0.65); font-size: 13px; font-weight: 500;
      text-decoration: none; margin-bottom: 2px;
      transition: background 0.15s, color 0.15s;
    }
    .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
    .nav-item.active { background: rgba(255,255,255,0.12); color: #fff; }
    .nav-item.active .nav-icon { color: var(--verde); }
    .nav-icon { width: 16px; height: 16px; flex-shrink: 0; }
    .nav-badge {
      margin-left: auto; background: var(--verde);
      color: #fff; font-size: 10px; font-weight: 700;
      padding: 2px 6px; border-radius: 100px; min-width: 18px; text-align: center;
    }
    .nav-badge.red { background: var(--red); }
    .nav-badge.amber { background: var(--amber); }

    .sidebar-bottom {
      margin-top: auto; padding: 12px;
      border-top: 1px solid rgba(255,255,255,0.08);
    }
    .user-card {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; border-radius: 8px;
      background: rgba(255,255,255,0.06);
    }
    .user-avatar {
      width: 32px; height: 32px; border-radius: 50%;
      background: var(--verde); display: flex; align-items: center;
      justify-content: center; font-size: 13px; font-weight: 700;
      color: #fff; flex-shrink: 0; text-transform: uppercase;
    }
    .user-info { flex: 1; min-width: 0; }
    .user-name { font-size: 12px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .user-role { font-size: 10px; color: rgba(255,255,255,0.4); text-transform: capitalize; }
    .btn-logout {
      width: 28px; height: 28px; border-radius: 6px;
      background: none; border: none; cursor: pointer;
      color: rgba(255,255,255,0.4); display: flex;
      align-items: center; justify-content: center;
      transition: background 0.15s, color 0.15s; flex-shrink: 0;
    }
    .btn-logout:hover { background: rgba(239,68,68,0.15); color: #ef4444; }

    /* ── Header ── */
    .header {
      position: fixed; top: 0; left: var(--sidebar-w); right: 0;
      height: var(--header-h); background: var(--header-bg);
      border-bottom: 1px solid var(--header-borde);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 24px; z-index: 90; transition: background 0.2s, border-color 0.2s;
    }
    .header-title {
      font-family: 'Outfit', sans-serif; font-size: 16px;
      font-weight: 600; color: var(--azul);
    }
    .header-right { display: flex; align-items: center; gap: 12px; }
    .header-date { font-size: 12px; color: var(--gris-texto); }
    .notif-btn {
      width: 36px; height: 36px; border-radius: 8px;
      background: var(--gris-bg); border: 1px solid var(--gris-borde);
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      color: var(--gris-texto); position: relative; transition: background 0.15s;
    }
    .notif-btn:hover { background: var(--azul-light); color: var(--azul); }
    .notif-dot {
      position: absolute; top: 6px; right: 6px;
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--red); border: 2px solid #fff;
    }

    /* ── Main ── */
    .main {
      margin-left: var(--sidebar-w);
      margin-top: var(--header-h);
      padding: 28px 28px;
      min-height: calc(100vh - var(--header-h));
    }

    /* ── Utilidades globales ── */
    .page-header { margin-bottom: 24px; }
    .page-header h1 { font-family: 'Outfit', sans-serif; font-size: 22px; font-weight: 700; color: var(--azul); }
    .page-header p { font-size: 13px; color: var(--gris-texto); margin-top: 4px; }

    .card {
      background: var(--gris-card); border-radius: 12px;
      border: 1px solid var(--gris-borde); padding: 20px;
    }

    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 9px 16px; border-radius: 8px; font-size: 13px;
      font-weight: 600; font-family: 'Inter', sans-serif;
      cursor: pointer; border: none; text-decoration: none;
      transition: all 0.15s;
    }
    .btn-primary { background: var(--azul); color: #fff; }
    .btn-primary:hover { background: var(--azul-mid); box-shadow: 0 4px 12px rgba(26,58,143,0.25); }
    .btn-success { background: var(--verde); color: #fff; }
    .btn-success:hover { background: var(--verde-dark); }
    .btn-outline { background: #fff; color: var(--azul); border: 1.5px solid var(--gris-borde); }
    .btn-outline:hover { border-color: var(--azul); background: var(--azul-light); }
    .btn-danger { background: var(--red); color: #fff; }
    .btn-sm { padding: 6px 12px; font-size: 12px; }

    /* Sidebar colapsable en desktop */
    .sidebar { transition: width 0.25s ease, transform 0.25s ease; }
    .sidebar.collapsed { width: 0; overflow: hidden; }
    .header { transition: left 0.25s ease; }
    .main { transition: margin-left 0.25s ease; }
    body.sidebar-collapsed .header { left: 0; }
    body.sidebar-collapsed .main { margin-left: 0; }

    /* Botón toggle desktop */
    .btn-toggle-desktop {
      display: flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; border-radius: 7px;
      background: var(--gris-bg); border: 1px solid var(--gris-borde);
      cursor: pointer; color: var(--gris-texto); flex-shrink: 0;
      transition: background 0.15s, color 0.15s;
    }
    .btn-toggle-desktop:hover { background: var(--azul-light); color: var(--azul); }

    @media (max-width: 768px) {
      .sidebar { transform: translateX(-100%); }
      .header { left: 0; }
      .main { margin-left: 0; padding: 16px; }
      .btn-toggle-desktop { display: none; }
    }
  </style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div style="background:#fff;border-radius:7px;padding:5px 10px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0">
      <img src="<?= APP_URL ?>/assets/logo-ggi.png?v=<?= filemtime(dirname(__DIR__,2).'/assets/logo-ggi.png') ?>" alt="GGI Logo">
    </div>
    <div class="sidebar-logo-text">
      <strong>Group Global ISO</strong>
      <span>Sistema v1.0</span>
    </div>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Principal</div>
    <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-item <?= ($active_menu??'')==='dashboard' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Panel Principal
    </a>
    <?php if (can('clientes')): ?>
    <a href="<?= APP_URL ?>/modules/clientes/index.php" class="nav-item <?= ($active_menu??'')==='clientes' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Empresas Clientes
    </a>
    <?php endif; ?>
    <?php if (can('certificados')): ?>
    <a href="<?= APP_URL ?>/modules/certificados/index.php" class="nav-item <?= ($active_menu??'')==='certificados' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
      Certificaciones ISO
    </a>
    <?php endif; ?>
    <?php if (can('auditorias')): ?>
    <a href="<?= APP_URL ?>/modules/auditorias/index.php" class="nav-item <?= ($active_menu??'')==='auditorias' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      Auditorías
    </a>
    <?php endif; ?>
    <?php if (can('envios')): ?>
    <a href="<?= APP_URL ?>/modules/envios/index.php" class="nav-item <?= ($active_menu??'')==='envios' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
      Despacho de Certificados
    </a>
    <?php endif; ?>
    <?php if (can('cotizaciones')): ?>
    <a href="<?= APP_URL ?>/modules/cotizaciones/index.php" class="nav-item <?= ($active_menu??'')==='cotizaciones' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Propuestas Comerciales
    </a>
    <?php endif; ?>
  </div>

  <?php if (can('mantenimiento')): ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label">Mantenimiento</div>
    <a href="<?= APP_URL ?>/modules/mantenimiento/normas.php" class="nav-item <?= ($active_menu??'')==='normas' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M12 2v2M12 20v2M2 12h2M20 12h2M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41"/></svg>
      Normas ISO
    </a>
    <a href="<?= APP_URL ?>/modules/mantenimiento/casas.php" class="nav-item <?= ($active_menu??'')==='casas' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
      Casas Certificadoras
    </a>
    <a href="<?= APP_URL ?>/modules/mantenimiento/agencias.php" class="nav-item <?= ($active_menu??'')==='agencias' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      Agencias de Envío
    </a>
    <a href="<?= APP_URL ?>/modules/mantenimiento/estados_envio.php" class="nav-item <?= ($active_menu??'')==='estados_envio' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Estados de Envío
    </a>
    <a href="<?= APP_URL ?>/modules/mantenimiento/usuarios.php" class="nav-item <?= ($active_menu??'')==='usuarios' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Gestión de Usuarios
    </a>
    <?php if (has_role('gerente_general')): ?>
    <a href="<?= APP_URL ?>/modules/mantenimiento/configuracion.php" class="nav-item <?= ($active_menu??'')==='configuracion' ? 'active':'' ?>">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2"/></svg>
      Configuración
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="sidebar-bottom">
    <div class="user-card">
      <div class="user-avatar"><?= strtoupper(substr($user['nombre'],0,1).substr($user['apellido'],0,1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= sanitize($user['nombre'].' '.$user['apellido']) ?></div>
        <div class="user-role"><?= str_replace('_',' ', $user['rol']) ?></div>
      </div>
      <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout" title="Cerrar sesión">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </a>
    </div>
  </div>
</aside>

<!-- Header -->
<header class="header">
  <!-- Toggle móvil -->
  <button class="btn-menu" onclick="toggleSidebar()" aria-label="Menú"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
  <!-- Toggle desktop -->
  <button class="btn-toggle-desktop" onclick="toggleSidebarDesktop()" title="Ocultar/mostrar menú">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="18" rx="1"/><path d="M14 9l3 3-3 3"/></svg>
  </button>
  <div class="header-title"><?= sanitize($page_title ?? 'Dashboard') ?></div>
  <div class="header-right">
    <span class="header-date"><?= date('d/m/Y') ?></span>
    <!-- Toggle tema oscuro/claro -->
    <button class="notif-btn" id="theme-toggle" onclick="toggleTheme()" title="Cambiar tema" aria-label="Cambiar tema">
      <!-- Icono luna (visible en claro) -->
      <svg id="icon-dark" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
      </svg>
      <!-- Icono sol (visible en oscuro) -->
      <svg id="icon-light" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none">
        <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
      </svg>
    </button>
    <button class="notif-btn" onclick="window.location='<?= APP_URL ?>/modules/notificaciones/index.php'" title="<?= $_notif_count ?> notificación(es) sin leer" style="position:relative">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      <?php if ($_notif_count > 0): ?>
      <span style="position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;font-size:9px;font-weight:700;padding:1px 4px;border-radius:100px;min-width:16px;text-align:center;line-height:14px;">
        <?= $_notif_count > 99 ? '99+' : $_notif_count ?>
      </span>
      <?php endif; ?>
    </button>
  </div>
</header>

<!-- Contenido principal -->
<main class="main">

<!-- Overlay móvil -->
<div id="sidebar-overlay" onclick="closeSidebar()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99;"></div>

<style>
  /* ── Botón hamburguesa (solo móvil) ── */
  .btn-menu {
    display:none; width:36px; height:36px; border-radius:8px;
    background:var(--gris-bg); border:1px solid var(--gris-borde);
    cursor:pointer; align-items:center; justify-content:center;
    color:var(--azul); flex-shrink:0;
  }
  @media (max-width:768px) {
    .btn-menu { display:flex; }
    .sidebar {
      transform:translateX(-100%);
      transition:transform 0.25s ease;
      z-index:101;
    }
    .sidebar.open { transform:translateX(0); }
    .header { left:0 !important; padding:0 16px; }
    .main { margin-left:0 !important; padding:16px !important; }
  }
</style>
