<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general');

$pdo = Database::get();
$msg = '';
$msg_type = 'ok';

// ── Instalación automática de tablas si no existen ──────────────────────────
$tablas_ok = false;
try {
    $pdo->query("SELECT 1 FROM rol_permisos LIMIT 1");
    $pdo->query("SELECT 1 FROM roles LIMIT 1");
    $tablas_ok = true;
} catch (\Throwable $e) {
    // Ejecutar el script SQL de instalación
    $sql_file = __DIR__ . '/roles_install.sql';
    if (file_exists($sql_file)) {
        try {
            $sql = file_get_contents($sql_file);
            // Eliminar comentarios antes de dividir por ';' para que no se salten sentencias
            $sql_clean = preg_replace('/--[^\n]*/', '', $sql);
            $sql_clean = preg_replace('/\/\*.*?\*\//s', '', $sql_clean);
            foreach (array_filter(array_map('trim', explode(';', $sql_clean))) as $stmt) {
                if ($stmt) {
                    $pdo->exec($stmt);
                }
            }
            $tablas_ok = true;
            $msg = 'Tablas de roles y permisos instaladas correctamente.';
        } catch (\Throwable $ex) {
            $msg = 'Error al instalar tablas: ' . $ex->getMessage();
            $msg_type = 'error';
        }
    }
}


// ── Definición maestra de módulos ────────────────────────────────────────────
$modulos = [
    'clientes'       => ['label' => 'Empresas / Clientes',  'grupo' => 'Operativo'],
    'certificados'   => ['label' => 'Certificados',         'grupo' => 'Operativo'],
    'cotizaciones'   => ['label' => 'Cotizaciones',         'grupo' => 'Operativo'],
    'envios'         => ['label' => 'Envíos',               'grupo' => 'Operativo'],
    'auditorias'     => ['label' => 'Auditorías',           'grupo' => 'Operativo'],
    'documentos'     => ['label' => 'Documentos',           'grupo' => 'Operativo'],
    'pagos'          => ['label' => 'Pagos',                'grupo' => 'Comercial'],
    'bonos'          => ['label' => 'Bonos',                'grupo' => 'Comercial'],
    'metas'          => ['label' => 'Metas',                'grupo' => 'Comercial'],
    'reportes'       => ['label' => 'Reportes',             'grupo' => 'Análisis'],
    'comunicaciones' => ['label' => 'Comunicaciones',       'grupo' => 'Análisis'],
    'notificaciones' => ['label' => 'Notificaciones',       'grupo' => 'Análisis'],
    'whatsapp'       => ['label' => 'WhatsApp',             'grupo' => 'Análisis'],
    'mantenimiento'  => ['label' => 'Mantenimiento',        'grupo' => 'Sistema'],
    'clientes_ver'   => ['label' => 'Ver Clientes (solo)',  'grupo' => 'Sistema'],
];

// ── Leer roles y permisos desde BD ──────────────────────────────────────────
$roles_db   = [];
$permisos_db = []; // ['rol' => ['modulo' => true/false]]

if ($tablas_ok) {
    try {
        $roles_db = $pdo->query("SELECT * FROM roles ORDER BY orden ASC")->fetchAll(\PDO::FETCH_ASSOC);

        $rows = $pdo->query("SELECT rol, modulo, activo FROM rol_permisos")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $permisos_db[$r['rol']][$r['modulo']] = (bool)$r['activo'];
        }
    } catch (\Throwable $e) {
        $msg = 'Error al leer datos: ' . $e->getMessage();
        $msg_type = 'error';
    }
}

// ── Conteo de usuarios por rol ───────────────────────────────────────────────
$users_por_rol = [];
try {
    $rows = $pdo->query("SELECT rol, COUNT(*) AS total FROM usuarios WHERE activo=1 GROUP BY rol")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $users_por_rol[$r['rol']] = (int)$r['total'];
    }
} catch (\Throwable $e) {}

// ── Colores y labels de roles ────────────────────────────────────────────────
$roles_meta = [];
foreach ($roles_db as $r) {
    $roles_meta[$r['nombre']] = $r;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
$page_title  = 'Roles y Permisos';
$active_menu = 'roles';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  /* ── Page layout ── */
  .rp-wrap   { max-width: 1100px; }
  .rp-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
  .rp-title  { font-size: 22px; font-weight: 700; color: var(--gris-dark); display: flex; align-items: center; gap: 10px; }

  /* ── Role cards ── */
  .roles-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(195px, 1fr)); gap: 14px; margin-bottom: 28px; }
  .role-card  {
    background: var(--gris-card); border: 1px solid var(--gris-borde);
    border-radius: 12px; padding: 16px 18px; cursor: pointer;
    transition: box-shadow .15s, transform .15s; position: relative; overflow: hidden;
  }
  .role-card:hover   { box-shadow: 0 4px 18px rgba(0,0,0,.1); transform: translateY(-2px); }
  .role-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; border-radius:12px 12px 0 0; }
  .role-card-icon    { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; margin-bottom:10px; }
  .role-card-name    { font-size:14px; font-weight:700; color:var(--gris-dark); margin-bottom:3px; }
  .role-card-desc    { font-size:11.5px; color:var(--gris-texto); line-height:1.5; margin-bottom:10px; min-height:34px; }
  .role-card-footer  { display:flex; align-items:center; justify-content:space-between; }
  .role-card-users   { font-size:11px; font-weight:600; color:var(--gris-texto); display:flex; align-items:center; gap:4px; }
  .role-badge-lock   { font-size:10px; background:var(--s-info-bg); color:#64748b; border:1px solid #e2e8f0; border-radius:20px; padding:2px 8px; font-weight:600; }
  .btn-edit-role     { width:26px; height:26px; border:1px solid var(--gris-borde); border-radius:7px; background:var(--gris-bg); cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--gris-texto); transition:.15s; }
  .btn-edit-role:hover { background:var(--azul-light); color:var(--azul); border-color:var(--azul); }
  .btn-del-role      { width:26px; height:26px; border:1px solid var(--gris-borde); border-radius:7px; background:var(--gris-bg); cursor:pointer; display:flex; align-items:center; justify-content:center; color:#ef4444; transition:.15s; margin-left:4px; }
  .btn-del-role:hover { background:var(--s-err-bg); border-color:#ef4444; }
  .btn-new-role { display:inline-flex; align-items:center; gap:7px; padding:9px 18px; background:var(--azul); color:#fff; border:none; border-radius:9px; font-size:13px; font-weight:600; cursor:pointer; transition:.15s; font-family:inherit; }
  .btn-new-role:hover { background:var(--azul-dark); }
  .slug-preview { font-size:11px; color:var(--gris-texto); margin-top:4px; font-family:monospace; }

  /* ── Permission matrix ── */
  .matrix-card  { background:var(--gris-card); border:1px solid var(--gris-borde); border-radius:12px; overflow:hidden; }
  .matrix-hdr   { padding:16px 22px; border-bottom:1px solid var(--gris-borde); background:var(--gris-bg); display:flex; align-items:center; gap:10px; }
  .matrix-title { font-size:14px; font-weight:700; color:var(--gris-dark); }
  .matrix-sub   { font-size:12px; color:var(--gris-texto); }
  .matrix-wrap  { overflow-x: auto; }

  .perm-table   { width:100%; border-collapse:collapse; min-width:700px; }
  .perm-table th, .perm-table td { padding:0; }

  /* sticky primera columna */
  .perm-table .col-mod   { width:210px; min-width:180px; position:sticky; left:0; z-index:2; background:var(--gris-card); }
  .perm-table thead .col-mod { background:var(--gris-bg); z-index:3; }

  /* header row */
  .perm-table thead th { background:var(--gris-bg); border-bottom:2px solid var(--gris-borde); }
  .th-mod-cell  { padding:14px 18px; font-size:11px; font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.5px; text-align:left; border-right:1px solid var(--gris-borde); }
  .th-rol-cell  { padding:12px 10px; text-align:center; min-width:110px; border-right:1px solid var(--gris-borde); }
  .th-rol-cell:last-child { border-right:none; }
  .th-rol-name  { font-size:12px; font-weight:700; color:var(--gris-dark); display:block; margin-bottom:3px; }
  .th-rol-badge { display:inline-block; font-size:10px; font-weight:600; border-radius:20px; padding:2px 8px; }

  /* grupo separator */
  .perm-table .row-group td { padding:7px 18px 4px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#475569; background:var(--s-info-bg); border-bottom:1px solid var(--gris-borde); }
  :root[data-tema="oscuro"] .perm-table .row-group td { background:#1e2535 !important; color:#94a3b8 !important; }

  /* data rows */
  .perm-table .row-data td { border-bottom:1px solid var(--gris-borde); }
  .perm-table .row-data:last-child td { border-bottom:none; }
  .perm-table .row-data:hover .col-mod,
  .perm-table .row-data:hover td { background:var(--bg-surface2); }
  :root[data-tema="oscuro"] .perm-table .row-data:hover td { background:#1e2533; }

  .td-mod-cell  { padding:11px 18px; font-size:13px; color:var(--gris-dark); font-weight:500; border-right:1px solid var(--gris-borde); }
  .td-perm-cell { text-align:center; border-right:1px solid var(--gris-borde); min-width:110px; }
  .td-perm-cell:last-child { border-right:none; }

  /* Toggle switch */
  .toggle-wrap { display:flex; align-items:center; justify-content:center; padding:8px 0; }
  .toggle      { position:relative; width:38px; height:22px; cursor:pointer; }
  .toggle input { opacity:0; width:0; height:0; position:absolute; }
  .toggle-track {
    position:absolute; inset:0; border-radius:11px;
    background:var(--s-info-bg); transition:background .2s;
  }
  .toggle input:checked ~ .toggle-track { background:var(--verde); }
  .toggle-track::after {
    content:''; position:absolute; left:3px; top:3px;
    width:16px; height:16px; border-radius:50%; background:var(--bg-surface);
    box-shadow:0 1px 3px rgba(0,0,0,.2); transition:transform .2s;
  }
  .toggle input:checked ~ .toggle-track::after { transform:translateX(16px); }
  /* Disabled (gerente_general) */
  .toggle.disabled { cursor:not-allowed; opacity:.55; }
  .toggle.disabled .toggle-track { background:var(--verde) !important; }
  /* Saving animation */
  .toggle.saving .toggle-track { background:#93c5fd !important; }

  /* ── Modal editar rol (moved to ggi-modal-* classes in design-system.css) ── */
  .mfield     { margin-bottom:14px; }
  .mfield label { display:block; font-size:11px; font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
  .mfield input, .mfield textarea, .mfield select {
    width:100%; padding:9px 11px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:inherit; outline:none; background:var(--gris-card); color:var(--gris-dark); transition:.15s;
  }
  .mfield input:focus, .mfield textarea:focus { border-color:var(--azul); box-shadow:0 0 0 3px rgba(29,78,216,.08); }
  .mfield textarea { resize:vertical; min-height:70px; }
  .color-row  { display:flex; align-items:center; gap:10px; }
  .color-row input[type=color] { width:44px; height:36px; padding:2px; border-radius:8px; border:1.5px solid var(--gris-borde); cursor:pointer; }

  /* ── Alert ── */
  .rp-alert { padding:10px 16px; border-radius:9px; font-size:13px; font-weight:500; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .rp-alert.ok    { background:var(--s-ok-bg); color:var(--s-ok-tx); border:1px solid var(--s-ok-brd); }
  .rp-alert.error { background:var(--s-err-bg); color:var(--s-err-tx); border:1px solid var(--s-err-brd); }

  /* dark mode */
  :root[data-tema="oscuro"] .matrix-card,
  :root[data-tema="oscuro"] .role-card,
  :root[data-tema="oscuro"] .ggi-modal-box   { background:#1e2533; border-color:#2d3748; }
  :root[data-tema="oscuro"] .matrix-hdr  { background:#161d2b; }
  :root[data-tema="oscuro"] .perm-table thead th { background:#161d2b; }
  :root[data-tema="oscuro"] .perm-table .col-mod { background:#1e2533; }
  :root[data-tema="oscuro"] .perm-table thead .col-mod { background:#161d2b; }
  :root[data-tema="oscuro"] .toggle-track { background:#475569; }
  :root[data-tema="oscuro"] .rp-alert.ok    { background:#14532d; color:#86efac; border-color:#1a6b3a; }
  :root[data-tema="oscuro"] .rp-alert.error { background:#450a0a; color:#fca5a5; border-color:var(--s-err-tx); }
  :root[data-tema="oscuro"] .mfield input,
  :root[data-tema="oscuro"] .mfield textarea { background:#161d2b; border-color:#2d3748; color:#e2e8f0; }
  :root[data-tema="oscuro"] .ggi-btn-confirm-cancel { background:#2d3748; color:#cbd5e1; border-color:#475569; }
  :root[data-tema="oscuro"] .btn-edit-role   { background:#2d3748; border-color:#475569; color:#94a3b8; }
  :root[data-tema="oscuro"] .role-badge-lock { background:#2d3748; color:#94a3b8; border-color:#475569; }
  :root[data-tema="oscuro"] .ggi-modal-close     { background:#2d3748; color:#94a3b8; }
  :root[data-tema="oscuro"] .th-rol-name     { color:#e2e8f0; }
  :root[data-tema="oscuro"] .td-mod-cell     { color:#cbd5e1; }

  @media(max-width:640px) {
    .roles-grid { grid-template-columns: 1fr 1fr; }
    .rp-header  { flex-direction:column; align-items:flex-start; }
  }
  @media(max-width:400px) {
    .roles-grid { grid-template-columns: 1fr; }
  }
</style>

<div class="rp-wrap">

  <!-- Header -->
  <div class="rp-header">
    <div>
      <div class="rp-title">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="var(--azul)" stroke-width="2">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        Roles y Permisos
      </div>
      <div style="font-size:13px;color:var(--gris-texto);margin-top:4px">
        Configura los accesos de cada rol al sistema. Los cambios se aplican de inmediato.
      </div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <?php if (!$tablas_ok): ?>
      <form method="post">
        <?= csrf_field() ?>
        <button type="submit" class="btn-primary" style="display:flex;align-items:center;gap:6px">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
          Instalar tablas
        </button>
      </form>
      <?php endif; ?>
      <?php if ($tablas_ok): ?>
      <button class="btn-new-role" onclick="abrirModalCrear()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Nuevo rol
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($msg): ?>
  <div class="rp-alert <?= $msg_type ?>">
    <?php if ($msg_type === 'ok'): ?>
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    <?php else: ?>
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?php endif; ?>
    <?= sanitize($msg) ?>
  </div>
  <?php endif; ?>

  <?php if ($tablas_ok): ?>

  <!-- Role cards -->
  <div class="roles-grid">
    <?php foreach ($roles_db as $r):
      $color    = $r['color'] ?? '#1A3A8F';
      $usuarios = $users_por_rol[$r['nombre']] ?? 0;
      $is_admin = $r['nombre'] === 'gerente_general';
      $rgb      = ltrim($color, '#');
      $r_hex    = hexdec(substr($rgb,0,2));
      $g_hex    = hexdec(substr($rgb,2,2));
      $b_hex    = hexdec(substr($rgb,4,2));
    ?>
    <div class="role-card" style="--rc:<?= $color ?>" onclick="<?= $is_admin ? '' : "abrirModal('{$r['nombre']}')" ?>">
      <div style="position:absolute;top:0;left:0;right:0;height:4px;background:<?= $color ?>;border-radius:12px 12px 0 0"></div>
      <div class="role-card-icon" style="background:rgba(<?=$r_hex?>,<?=$g_hex?>,<?=$b_hex?>,.12)">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="<?= $color ?>" stroke-width="2">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
      </div>
      <div class="role-card-name"><?= sanitize($r['label']) ?></div>
      <div class="role-card-desc"><?= sanitize($r['descripcion'] ?? '') ?></div>
      <div class="role-card-footer">
        <div class="role-card-users">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <?= $usuarios ?> <?= $usuarios === 1 ? 'usuario' : 'usuarios' ?>
        </div>
        <?php if ($is_admin): ?>
          <span class="role-badge-lock">🔒 Total</span>
        <?php else: ?>
          <div style="display:flex;align-items:center">
            <button class="btn-edit-role" title="Editar" onclick="event.stopPropagation();abrirModal('<?= $r['nombre'] ?>')">
              <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <?php if ($usuarios === 0): ?>
            <button class="btn-del-role" title="Eliminar rol" onclick="event.stopPropagation();confirmarEliminar('<?= $r['nombre'] ?>','<?= htmlspecialchars($r['label'],ENT_QUOTES) ?>')">
              <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </button>
            <?php else: ?>
            <button class="btn-del-role" title="<?= $usuarios ?> usuario(s) asignado(s) — no se puede eliminar" onclick="event.stopPropagation();mostrarToast('Hay <?= $usuarios ?> usuario(s) con este rol. Reasígnalos primero.','error')" style="opacity:.4;cursor:not-allowed">
              <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Permission matrix -->
  <div class="matrix-card">
    <div class="matrix-hdr">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--azul)" stroke-width="2">
        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
        <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
      </svg>
      <div>
        <div class="matrix-title">Matriz de permisos</div>
        <div class="matrix-sub">Activa o desactiva módulos por rol. Los cambios son inmediatos.</div>
      </div>
    </div>

    <div class="matrix-wrap">
      <table class="perm-table">
        <thead>
          <tr>
            <th scope="col" class="col-mod">
              <div class="th-mod-cell">Módulo</div>
            </th>
            <?php foreach ($roles_db as $r):
              $color = $r['color'] ?? '#1A3A8F';
              $is_admin = $r['nombre'] === 'gerente_general';
            ?>
            <th scope="col">
              <div class="th-rol-cell">
                <span class="th-rol-name"><?= sanitize($r['label']) ?></span>
                <?php if ($is_admin): ?>
                  <span class="th-rol-badge" style="background:<?= $color ?>22;color:<?= $color ?>">Acceso total</span>
                <?php else: ?>
                  <?php
                    $cnt = count(array_filter($permisos_db[$r['nombre']] ?? [], fn($v) => $v));
                  ?>
                  <span class="th-rol-badge" style="background:var(--s-info-bg);color:#64748b"><?= $cnt ?>/<?= count($modulos) ?></span>
                <?php endif; ?>
              </div>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php
          $grupos_vistos = [];
          foreach ($modulos as $slug => $info):
            $grupo = $info['grupo'];
            $nuevogrup = !in_array($grupo, $grupos_vistos);
            if ($nuevogrup) $grupos_vistos[] = $grupo;
          ?>
          <?php if ($nuevogrup): ?>
          <tr class="row-group">
            <td colspan="<?= count($roles_db) + 1 ?>"><?= $grupo ?></td>
          </tr>
          <?php endif; ?>
          <tr class="row-data">
            <td class="col-mod">
              <div class="td-mod-cell"><?= sanitize($info['label']) ?></div>
            </td>
            <?php foreach ($roles_db as $r):
              $is_admin = $r['nombre'] === 'gerente_general';
              $tiene    = $is_admin ? true : ($permisos_db[$r['nombre']][$slug] ?? false);
              $toggle_id = 'tog_' . $r['nombre'] . '_' . $slug;
            ?>
            <td class="td-perm-cell">
              <div class="toggle-wrap">
                <label class="toggle <?= $is_admin ? 'disabled' : '' ?>" title="<?= sanitize($r['label']) ?>: <?= sanitize($info['label']) ?>">
                  <input
                    type="checkbox"
                    id="<?= $toggle_id ?>"
                    <?= $tiene ? 'checked' : '' ?>
                    <?= $is_admin ? 'disabled' : '' ?>
                    data-rol="<?= $r['nombre'] ?>"
                    data-modulo="<?= $slug ?>"
                    onchange="togglePermiso(this)"
                  >
                  <div class="toggle-track"></div>
                </label>
              </div>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php else: ?>
  <div style="text-align:center;padding:60px 20px;color:var(--gris-texto)">
    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 14px;display:block;opacity:.35"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    <div style="font-size:15px;font-weight:600;margin-bottom:6px">Tablas no instaladas</div>
    <div style="font-size:13px">Haz clic en "Instalar tablas" para inicializar el sistema de permisos.</div>
  </div>
  <?php endif; ?>

</div><!-- /.rp-wrap -->

<!-- Modal NUEVO Rol -->
<div class="ggi-modal-overlay" id="modal-crear-rol" onclick="if(event.target===this)cerrarModalCrear()">
  <div class="ggi-modal-box">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="14"/><line x1="9" y1="11" x2="15" y2="11"/></svg>
        Nuevo rol
      </h3>
      <button type="button" class="ggi-modal-close" onclick="cerrarModalCrear()" aria-label="Cerrar">✕</button>
    </div>
    <form id="form-crear-rol" onsubmit="crearRol(event)">
      <div class="ggi-modal-body">
        <div class="mfield">
          <label>Identificador interno <span class="req">*</span></label>
          <input type="text" id="cn-nombre" name="nombre" required maxlength="50"
            placeholder="ej: supervisor_ventas"
            oninput="previewSlug(this)"
            pattern="[a-z][a-z0-9_]{2,49}"
            title="Solo letras minúsculas, números y guión bajo. Debe empezar con letra."
          >
          <div class="slug-preview" id="cn-slug-preview">Se usará como clave interna del rol (no modificable después)</div>
        </div>
        <div class="mfield">
          <label>Etiqueta visible <span class="req">*</span></label>
          <input type="text" id="cn-label" name="label" required maxlength="100" placeholder="ej: Supervisor de Ventas">
        </div>
        <div class="mfield">
          <label>Descripción</label>
          <textarea id="cn-desc" name="descripcion" maxlength="500" placeholder="Breve descripción del rol y sus responsabilidades…"></textarea>
        </div>
        <div class="mfield">
          <label>Color de identificación</label>
          <div class="color-row">
            <input type="color" id="cn-color" name="color" value="#3B82F6">
            <span style="font-size:12px;color:var(--gris-texto)">Se usará en tarjetas y badges</span>
          </div>
        </div>
        <div style="background:var(--gris-bg);border:1px solid var(--gris-borde);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--gris-texto);line-height:1.6">
          💡 El nuevo rol se creará sin permisos. Actívalos después en la matriz.
        </div>
      </div>
      <div class="ggi-modal-footer">
        <button type="button" class="ggi-btn-confirm-cancel" onclick="cerrarModalCrear()">Cancelar</button>
        <button type="submit" class="ggi-btn-confirm-action" id="btn-crear-rol">
          <span id="btn-crear-txt">Crear rol</span>
        </button>
      </div>
    </form>
  </div>
</div>


<!-- Modal editar rol -->
<div class="ggi-modal-overlay" id="modal-rol" onclick="if(event.target===this)cerrarModal()">
  <div class="ggi-modal-box">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title warning">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#f59e0b" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Editar rol
      </h3>
      <button type="button" class="ggi-modal-close" onclick="cerrarModal()" aria-label="Cerrar">✕</button>
    </div>
    <form id="form-rol" onsubmit="guardarRol(event)">
      <input type="hidden" id="f-nombre" name="nombre">
      <div class="ggi-modal-body">
        <div class="mfield">
          <label>Nombre del rol (identificador)</label>
          <input type="text" id="f-nombre-display" disabled style="opacity:.6">
        </div>
        <div class="mfield">
          <label>Etiqueta visible <span class="req">*</span></label>
          <input type="text" id="f-label" name="label" required placeholder="Ej. Ejecutivo de Ventas">
        </div>
        <div class="mfield">
          <label>Descripción</label>
          <textarea id="f-desc" name="descripcion" placeholder="Breve descripción del rol y sus responsabilidades…"></textarea>
        </div>
        <div class="mfield">
          <label>Color de identificación</label>
          <div class="color-row">
            <input type="color" id="f-color" name="color" value="#1A3A8F">
            <span style="font-size:12px;color:var(--gris-texto)">Se usa en las tarjetas y badges del módulo</span>
          </div>
        </div>
      </div>
      <div class="ggi-modal-footer">
        <button type="button" class="ggi-btn-confirm-cancel" onclick="cerrarModal()">Cancelar</button>
        <button type="submit" class="ggi-btn-confirm-action" id="btn-guardar-rol">
          <span id="btn-guardar-txt">Guardar cambios</span>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
var CSRF  = document.querySelector('meta[name="csrf-token"]').content;
var AJAX  = '<?= APP_URL ?>/modules/mantenimiento/ajax_roles.php';
var ROLES = <?= json_encode(array_column($roles_db, null, 'nombre')) ?>;

// ── Toggle permiso ───────────────────────────────────────────────────────────
function togglePermiso(cb) {
  const label = cb.closest('.toggle');
  label.classList.add('saving');
  cb.disabled = true;

  fetch(AJAX, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
    body: JSON.stringify({ accion: 'toggle', rol: cb.dataset.rol, modulo: cb.dataset.modulo, activo: cb.checked ? 1 : 0 })
  })
  .then(r => r.json())
  .then(data => {
    label.classList.remove('saving');
    cb.disabled = false;
    if (!data.ok) {
      cb.checked = !cb.checked; // revertir
      mostrarToast('Error: ' + (data.msg || 'No se pudo guardar'), 'error');
    } else {
      actualizarContador(cb.dataset.rol);
      mostrarToast('Permiso ' + (cb.checked ? 'activado' : 'desactivado'), 'ok');
    }
  })
  .catch(() => {
    label.classList.remove('saving');
    cb.disabled = false;
    cb.checked = !cb.checked;
    mostrarToast('Error de conexión', 'error');
  });
}

// Actualizar el contador X/Y en el header de la columna de un rol
function actualizarContador(rolNombre) {
  const cbs = document.querySelectorAll(`input[data-rol="${rolNombre}"]`);
  const total  = cbs.length;
  const activos = [...cbs].filter(c => c.checked).length;
  // Buscar el badge en el header (th con nombre del rol)
  const ths = document.querySelectorAll('.th-rol-cell');
  ths.forEach(th => {
    const nm = th.querySelector('.th-rol-name');
    if (nm && nm.textContent.trim() === (ROLES[rolNombre]?.label || '')) {
      const badge = th.querySelector('.th-rol-badge');
      if (badge && !badge.textContent.includes('total')) badge.textContent = activos + '/' + total;
    }
  });
}

// ── Modal editar rol ─────────────────────────────────────────────────────────
function abrirModal(nombre) {
  const r = ROLES[nombre];
  if (!r) return;
  document.getElementById('f-nombre').value         = r.nombre;
  document.getElementById('f-nombre-display').value = r.nombre;
  document.getElementById('f-label').value          = r.label;
  document.getElementById('f-desc').value           = r.descripcion || '';
  document.getElementById('f-color').value          = r.color || '#1A3A8F';
  document.getElementById('modal-rol').classList.add('open');
}

function cerrarModal() {
  document.getElementById('modal-rol').classList.remove('open');
}

document.getElementById('modal-rol').addEventListener('click', e => {
  if (e.target === document.getElementById('modal-rol')) cerrarModal();
});

function guardarRol(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-guardar-rol');
  const txt = document.getElementById('btn-guardar-txt');
  txt.textContent = 'Guardando…';
  btn.disabled = true;

  const data = {
    accion:      'editar_rol',
    nombre:      document.getElementById('f-nombre').value,
    label:       document.getElementById('f-label').value,
    descripcion: document.getElementById('f-desc').value,
    color:       document.getElementById('f-color').value,
  };

  fetch(AJAX, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
    body: JSON.stringify(data)
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    txt.textContent = 'Guardar cambios';
    if (res.ok) {
      cerrarModal();
      mostrarToast('Rol actualizado', 'ok');
      setTimeout(() => location.reload(), 900);
    } else {
      mostrarToast('Error: ' + (res.msg || 'No se pudo guardar'), 'error');
    }
  })
  .catch(() => {
    btn.disabled = false;
    txt.textContent = 'Guardar cambios';
    mostrarToast('Error de conexión', 'error');
  });
}

// ── Modal CREAR ──────────────────────────────────────────────────────────────
function abrirModalCrear() {
  document.getElementById('form-crear-rol').reset();
  document.getElementById('cn-slug-preview').textContent = 'Se usará como clave interna del rol (no modificable después)';
  document.getElementById('modal-crear-rol').classList.add('open');
  setTimeout(() => document.getElementById('cn-nombre').focus(), 100);
}
function cerrarModalCrear() {
  document.getElementById('modal-crear-rol').classList.remove('open');
}
document.getElementById('modal-crear-rol').addEventListener('click', e => {
  if (e.target === document.getElementById('modal-crear-rol')) cerrarModalCrear();
});

function previewSlug(input) {
  // Auto-sanitize: lowercase, spaces→underscore, remove invalid chars
  let v = input.value.toLowerCase().replace(/\s+/g,'_').replace(/[^a-z0-9_]/g,'');
  if (v !== input.value) { input.value = v; }
  const preview = document.getElementById('cn-slug-preview');
  if (v.length >= 3) {
    preview.textContent = '✓ Identificador: ' + v;
    preview.style.color = 'var(--verde)';
  } else {
    preview.textContent = 'Mínimo 3 caracteres, solo a-z, 0-9, _';
    preview.style.color = 'var(--gris-texto)';
  }
}

function crearRol(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-crear-rol');
  const txt = document.getElementById('btn-crear-txt');
  txt.textContent = 'Creando…';
  btn.disabled = true;

  const data = {
    accion:      'crear_rol',
    nombre:      document.getElementById('cn-nombre').value.trim(),
    label:       document.getElementById('cn-label').value.trim(),
    descripcion: document.getElementById('cn-desc').value.trim(),
    color:       document.getElementById('cn-color').value,
  };

  fetch(AJAX, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
    body: JSON.stringify(data)
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    txt.textContent = 'Crear rol';
    if (res.ok) {
      cerrarModalCrear();
      mostrarToast('Rol "' + data.label + '" creado correctamente', 'ok');
      setTimeout(() => location.reload(), 900);
    } else {
      mostrarToast('Error: ' + (res.msg || 'No se pudo crear'), 'error');
    }
  })
  .catch(() => {
    btn.disabled = false;
    txt.textContent = 'Crear rol';
    mostrarToast('Error de conexión', 'error');
  });
}

// ── Modal ELIMINAR (usando GGIConfirm) ────────────────────────────────────────
function confirmarEliminar(nombre, label) {
  GGIConfirm.show({
    title: 'Eliminar rol',
    message: `¿Estás seguro de eliminar el rol <strong>${label}</strong>? Se eliminarán todos sus permisos configurados. Esta acción no se puede deshacer.`,
    icon: 'danger',
    confirmText: 'Eliminar',
    confirmColor: '#ef4444',
    onConfirm: () => {
      ejecutarEliminar(nombre);
    }
  });
}

function ejecutarEliminar(nombre) {
  fetch(AJAX, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
    body: JSON.stringify({ accion: 'eliminar_rol', nombre })
  })
  .then(r => r.json())
  .then(res => {
    if (res.ok) {
      mostrarToast('Rol eliminado', 'ok');
      setTimeout(() => location.reload(), 900);
    } else {
      mostrarToast('Error: ' + (res.msg || 'No se pudo eliminar'), 'error');
    }
  })
  .catch(() => {
    mostrarToast('Error de conexión', 'error');
  });
}

// ── Toast ────────────────────────────────────────────────────────────────────
function mostrarToast(msg, tipo) {
  let t = document.getElementById('rp-toast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'rp-toast';
    Object.assign(t.style, {
      position:'fixed', bottom:'24px', right:'24px', zIndex:'9999',
      padding:'11px 18px', borderRadius:'10px', fontSize:'13px', fontWeight:'600',
      boxShadow:'0 4px 20px rgba(0,0,0,.18)', transition:'opacity .3s',
      display:'flex', alignItems:'center', gap:'8px', maxWidth:'320px'
    });
    document.body.appendChild(t);
  }
  const isOk = tipo === 'ok';
  t.style.background = isOk ? 'var(--s-ok-bg)' : 'var(--s-err-bg)';
  t.style.color      = isOk ? 'var(--s-ok-tx)' : 'var(--s-err-tx)';
  t.style.border     = `1px solid ${isOk ? 'var(--s-ok-brd)' : 'var(--s-err-brd)'}`;
  t.innerHTML = (isOk
    ? '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>'
    : '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
  ) + msg;
  t.style.opacity = '1';
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.style.opacity = '0'; }, 3000);
}
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
