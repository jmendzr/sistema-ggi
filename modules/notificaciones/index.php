<?php
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();

$pdo = Database::get();
$uid = auth_user()['id'];

// ── Ver notificación: marcar como leída y redirigir ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ver'])) {
    $nid = (int)$_GET['ver'];
    $stmt = $pdo->prepare("SELECT url_accion FROM notificaciones WHERE id=? AND usuario_id=?");
    $stmt->execute([$nid, $uid]);
    $notif_ver = $stmt->fetch();
    if ($notif_ver) {
        $pdo->prepare("UPDATE notificaciones SET leida=1 WHERE id=? AND usuario_id=?")->execute([$nid, $uid]);
        if (!empty($notif_ver['url_accion'])) {
            header('Location: ' . $notif_ver['url_accion']);
            exit;
        }
    }
    header('Location: ' . APP_URL . '/modules/notificaciones/index.php');
    exit;
}

// ── Acciones POST / AJAX ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $es_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
               && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    csrf_verify();
    $accion = $_POST['accion'] ?? '';
    $nid    = (int)($_POST['id'] ?? 0);

    if ($accion === 'marcar_leida' && $nid) {
        $pdo->prepare("UPDATE notificaciones SET leida=1 WHERE id=? AND usuario_id=?")->execute([$nid, $uid]);
    } elseif ($accion === 'marcar_todas') {
        $pdo->prepare("UPDATE notificaciones SET leida=1 WHERE usuario_id=?")->execute([$uid]);
    } elseif ($accion === 'eliminar' && $nid) {
        $pdo->prepare("DELETE FROM notificaciones WHERE id=? AND usuario_id=?")->execute([$nid, $uid]);
    } elseif ($accion === 'limpiar_leidas') {
        $pdo->prepare("DELETE FROM notificaciones WHERE usuario_id=? AND leida=1")->execute([$uid]);
    }

    // Contar no leídas actualizado para devolver al cliente
    $no_leidas_new = (int)$pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leida=0")->execute([$uid])
        ? (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=$uid AND leida=0")->fetchColumn()
        : 0;

    if ($es_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'no_leidas' => $no_leidas_new]);
        exit;
    }

    // Fallback sin JS
    $filtro_redirect = $_POST['filtro'] ?? 'todas';
    header('Location: ' . APP_URL . '/modules/notificaciones/index.php?filtro=' . urlencode($filtro_redirect));
    exit;
}

$page_title  = 'Notificaciones';
$active_menu = 'notificaciones';
require_once __DIR__ . '/../../templates/layout.php';

$user = auth_user();

// ── Filtro ──
$filtro   = $_GET['filtro'] ?? 'todas';
$tipo_f   = $_GET['tipo']   ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$_pp_opts = [10, 20, 30];
$_pp_req  = (int)($_GET['per_page'] ?? $_SESSION['per_page_list'] ?? 10);
$per_page = in_array($_pp_req, $_pp_opts) ? $_pp_req : 10;
$_SESSION['per_page_list'] = $per_page;
$offset   = ($page - 1) * $per_page;

$where  = ['usuario_id = ?'];
$params = [$uid];

if ($filtro === 'no_leidas') { $where[] = 'leida = 0'; }
if ($filtro === 'leidas')    { $where[] = 'leida = 1'; }
if ($tipo_f)                 { $where[] = 'tipo = ?'; $params[] = $tipo_f; }

$sql_where = implode(' AND ', $where);

$total_notifs = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE $sql_where");
$total_notifs->execute($params);
$total = (int)$total_notifs->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT * FROM notificaciones WHERE $sql_where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$notifs = $stmt->fetchAll();

$no_leidas = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=$uid AND leida=0")->fetchColumn();

// Iconos y colores por tipo
function notif_meta(string $tipo): array { return gg_notif_icono($tipo); }

function tiempo_relativo(string $fecha): string {
    $diff = time() - strtotime($fecha);
    if ($diff < 60)     return 'Hace un momento';
    if ($diff < 3600)   return 'Hace ' . floor($diff/60) . ' min';
    if ($diff < 86400)  return 'Hace ' . floor($diff/3600) . ' h';
    if ($diff < 604800) return 'Hace ' . floor($diff/86400) . ' días';
    return date('d/m/Y', strtotime($fecha));
}
?>
<style>
.notif-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.notif-filtros { display:flex; gap:6px; flex-wrap:wrap; }
.filtro-btn {
  padding:7px 14px; border-radius:8px; font-size:12px; font-weight:600;
  border:1.5px solid var(--gris-borde); background:var(--bg-surface); color:var(--gris-texto);
  text-decoration:none; cursor:pointer; transition:all 0.15s;
}
.filtro-btn:hover, .filtro-btn.active { border-color:var(--azul); background:var(--azul-light); color:var(--azul); }
.filtro-btn .cnt { margin-left:5px; background:var(--azul); color:#fff; border-radius:100px; font-size:10px; padding:1px 6px; }
.btn-accion {
  display:inline-flex; align-items:center; gap:6px;
  padding:7px 14px; border-radius:8px; font-size:12px; font-weight:600;
  border:1.5px solid var(--gris-borde); background:var(--bg-surface); color:var(--gris-texto);
  cursor:pointer; font-family:'Inter',sans-serif; transition:all 0.15s;
}
.btn-accion:hover { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }
.btn-accion.danger:hover { border-color:var(--red); color:var(--red); background:var(--s-err-bg); }
.btn-accion:disabled { opacity:0.5; cursor:not-allowed; }

/* Móvil: chips de filtro en una fila con scroll horizontal (patrón estándar)
   en vez de apilarse en 3-4 filas; targets táctiles ≥44px. */
@media (max-width: 640px) {
  .notif-filtros {
    flex-wrap: nowrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 4px;
    max-width: 100%;
  }
  .notif-filtros::-webkit-scrollbar { height: 4px; }
  .notif-filtros::-webkit-scrollbar-thumb { background: var(--gris-borde); border-radius: 2px; }
  .filtro-btn { white-space: nowrap; flex-shrink: 0; }
}
@media (hover: none) {
  .filtro-btn, .btn-accion { min-height: 44px; display: inline-flex; align-items: center; }
}

.notif-list { display:flex; flex-direction:column; gap:0; background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
.notif-item {
  display:flex; align-items:flex-start; gap:14px;
  padding:16px 20px; border-bottom:1px solid var(--bg-surface2);
  transition:background 0.15s, opacity 0.25s, transform 0.25s; position:relative;
}
.notif-item:last-child { border-bottom:none; }
.notif-item.no-leida { background:var(--bg-surface2); }
.notif-item.no-leida::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--azul); border-radius:2px 0 0 2px; }
.notif-item:hover { background:var(--s-info-bg); }
.notif-item.removing { opacity:0; transform:translateX(20px); pointer-events:none; }

.notif-icono {
  width:40px; height:40px; border-radius:10px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
}
.notif-body { flex:1; min-width:0; }
.notif-titulo { font-size:13px; font-weight:600; color:var(--gris-dark); margin-bottom:3px; line-height:1.4; }
.notif-item.leida .notif-titulo { font-weight:500; color:var(--gris-texto); }
.notif-mensaje { font-size:12px; color:var(--gris-texto); line-height:1.5; margin-bottom:6px; }
.notif-meta { display:flex; align-items:center; gap:10px; font-size:11px; color:var(--gris-texto); }
.notif-tipo-badge { padding:2px 8px; border-radius:100px; font-size:10px; font-weight:600; }

.notif-acciones { display:flex; gap:6px; align-items:center; flex-shrink:0; }
.notif-btn-sm {
  width:28px; height:28px; border-radius:7px; border:1px solid var(--gris-borde);
  background:var(--bg-surface); cursor:pointer; display:flex; align-items:center; justify-content:center;
  color:var(--gris-texto); transition:all 0.15s;
}
.notif-btn-sm:hover { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }
.notif-btn-sm.del:hover { border-color:var(--red); color:var(--red); background:var(--s-err-bg); }
.notif-btn-sm:disabled { opacity:0.4; cursor:not-allowed; }

.notif-empty { padding:48px; text-align:center; color:var(--gris-texto); }
.notif-empty svg { opacity:0.2; display:block; margin:0 auto 12px; }

.paginacion { display:flex; justify-content:center; gap:6px; margin-top:20px; flex-wrap:wrap; }
.pag-btn { padding:7px 13px; border-radius:8px; font-size:12px; font-weight:600; border:1.5px solid var(--gris-borde); background:var(--bg-surface); color:var(--gris-texto); text-decoration:none; transition:all 0.15s; }
.pag-btn:hover, .pag-btn.active { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }
.pag-btn.disabled { opacity:0.4; pointer-events:none; }

.stats-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; margin-bottom:20px; }
.stat-mini { background:var(--bg-surface); border:1px solid var(--gris-borde); border-radius:10px; padding:14px 16px; text-align:center; }
.stat-mini .val { font-family:'Inter',sans-serif; font-size:22px; font-weight:700; color:var(--gris-dark); }
.stat-mini .lbl { font-size:11px; color:var(--gris-texto); margin-top:2px; }
</style>

<?php
$stats_tipos = $pdo->query("SELECT tipo, COUNT(*) as cnt FROM notificaciones WHERE usuario_id=$uid AND leida=0 GROUP BY tipo")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<?php if ($no_leidas > 0): ?>
<div class="stats-row" id="stats-row">
  <?php foreach (['cert_por_vencer'=>'Por vencer','cert_vencido'=>'Vencidos','auditoria_proxima'=>'Auditorías','envio_sin_confirmar'=>'Envíos','cotizacion_vencida'=>'Propuestas'] as $k => $lbl): ?>
    <?php if (!empty($stats_tipos[$k])): $m = notif_meta($k); ?>
    <div class="stat-mini" style="border-left:3px solid <?= $m['color'] ?>;">
      <div class="val" style="color:<?= $m['color'] ?>"><?= $stats_tipos[$k] ?></div>
      <div class="lbl"><?= $lbl ?></div>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="page-header" style="margin-bottom:16px;">
  <h1>Notificaciones</h1>
  <p id="notif-resumen"><span id="cnt-no-leidas"><?= $no_leidas ?></span> sin leer · <span id="cnt-total"><?= $total ?></span> en total</p>
</div>

<div class="notif-toolbar">
  <div class="notif-filtros">
    <a href="?filtro=todas"     class="filtro-btn <?= $filtro==='todas'?'active':'' ?>">Todas <span class="cnt" id="badge-todas"><?= $total ?></span></a>
    <a href="?filtro=no_leidas" class="filtro-btn <?= $filtro==='no_leidas'?'active':'' ?>">Sin leer <span class="cnt" style="background:var(--red)" id="badge-no-leidas"><?= $no_leidas ?></span></a>
    <a href="?filtro=leidas"    class="filtro-btn <?= $filtro==='leidas'?'active':'' ?>">Leídas</a>
  </div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;">
    <button type="button" id="btn-marcar-todas" class="btn-accion" <?= $no_leidas > 0 ? '' : 'style="display:none"' ?>
            onclick="accionBulk('marcar_todas')">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      Marcar todas leídas
    </button>
    <button type="button" class="btn-accion danger" onclick="accionBulk('limpiar_leidas')">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
      Limpiar leídas
    </button>
  </div>
</div>

<div class="notif-list" id="notif-list">
  <?php if (empty($notifs)): ?>
  <div class="notif-empty" id="notif-empty-state">
    <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
      <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
      <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
    </svg>
    <p style="font-size:14px;font-weight:600;color:var(--gris-dark);margin-bottom:4px;">
      <?= $filtro === 'no_leidas' ? 'Todo al día' : 'Sin notificaciones' ?>
    </p>
    <p style="font-size:13px;">
      <?= $filtro === 'no_leidas' ? 'No tienes notificaciones pendientes.' : 'Las notificaciones aparecerán aquí.' ?>
    </p>
  </div>
  <?php else: ?>
  <?php foreach ($notifs as $n):
    $meta  = notif_meta($n['tipo']);
    $leida = (int)$n['leida'];
  ?>
  <div class="notif-item <?= $leida ? 'leida' : 'no-leida' ?>" id="notif-<?= $n['id'] ?>" data-leida="<?= $leida ?>">
    <div class="notif-icono" style="background:<?= $meta['bg'] ?>">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="<?= $meta['color'] ?>" stroke-width="2">
        <?= $meta['icon'] ?>
      </svg>
    </div>
    <div class="notif-body">
      <div class="notif-titulo"><?= htmlspecialchars($n['titulo']) ?></div>
      <div class="notif-mensaje"><?= htmlspecialchars($n['mensaje']) ?></div>
      <div class="notif-meta">
        <span><?= tiempo_relativo($n['created_at']) ?></span>
        <span class="notif-tipo-badge" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>"><?= $meta['label'] ?></span>
        <?php if (!$leida): ?>
          <span class="punto-azul" style="width:6px;height:6px;border-radius:50%;background:var(--azul);display:inline-block;"></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="notif-acciones">
      <?php if ($n['url_accion']): ?>
      <a href="<?= APP_URL ?>/modules/notificaciones/index.php?ver=<?= $n['id'] ?>" class="notif-btn-sm" title="Ver detalle">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      </a>
      <?php endif; ?>
      <?php if (!$leida): ?>
      <button type="button" class="notif-btn-sm btn-leer" title="Marcar como leída"
              onclick="marcarLeida(<?= $n['id'] ?>, this)">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      </button>
      <?php endif; ?>
      <button type="button" class="notif-btn-sm del" title="Eliminar"
              onclick="eliminarNotif(<?= $n['id'] ?>, this)">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($total > 0): ?>
  <?= ggi_pager($total, $page, $per_page, [
      'per_page_options' => [10, 20, 30],
      'param_page'       => 'page',
      'noun'             => 'notificación',
      'noun_plural'      => 'notificaciones',
  ]) ?>
<?php endif; ?>

<script>
var CSRF_TOKEN  = '<?= csrf_token() ?>';
var API_URL     = '<?= APP_URL ?>/modules/notificaciones/index.php';
var FILTRO_ACT  = '<?= $filtro ?>';

// ── Petición AJAX genérica ──
async function notifAjax(accion, id = 0) {
    const fd = new FormData();
    fd.append('accion', accion);
    fd.append('_csrf', CSRF_TOKEN);
    if (id) fd.append('id', id);
    fd.append('filtro', FILTRO_ACT);

    const r = await fetch(API_URL, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    });
    return r.ok ? r.json() : null;
}

// ── Actualizar badges globales (sidebar + header) ──
function actualizarBadges(noLeidas) {
    // Sidebar badge
    const nb = document.getElementById('nav-badge-notif');
    if (nb) {
        if (noLeidas <= 0) { nb.style.display = 'none'; }
        else { nb.style.display = ''; nb.textContent = noLeidas > 9 ? '9+' : noLeidas; }
    }
    // Header badge (campana)
    const hb = document.querySelector('.notif-btn .notif-badge');
    if (hb) {
        if (noLeidas <= 0) { hb.style.display = 'none'; }
        else { hb.style.display = ''; hb.textContent = noLeidas > 99 ? '99+' : noLeidas; }
    }
    // Contador en página
    const cNL = document.getElementById('cnt-no-leidas');
    if (cNL) cNL.textContent = noLeidas;
    const bNL = document.getElementById('badge-no-leidas');
    if (bNL) bNL.textContent = noLeidas;
    // Botón "marcar todas"
    const bmt = document.getElementById('btn-marcar-todas');
    if (bmt) bmt.style.display = noLeidas > 0 ? '' : 'none';
}

// ── Actualizar contador total ──
function decrementarTotal(cantidad = 1) {
    const cT = document.getElementById('cnt-total');
    const bT = document.getElementById('badge-todas');
    if (cT) { const v = Math.max(0, parseInt(cT.textContent) - cantidad); cT.textContent = v; }
    if (bT) { const v = Math.max(0, parseInt(bT.textContent) - cantidad); bT.textContent = v; }
}

// ── Mostrar estado vacío si no quedan ítems ──
function checkVacio() {
    const items = document.querySelectorAll('#notif-list .notif-item');
    if (items.length === 0) {
        document.getElementById('notif-list').innerHTML =
            '<div class="notif-empty">' +
            '<svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>' +
            '<p style="font-size:14px;font-weight:600;color:var(--gris-dark);margin-bottom:4px;">Sin notificaciones</p>' +
            '<p style="font-size:13px;">Las notificaciones aparecerán aquí.</p></div>';
    }
}

// ── Remover ítem del DOM con animación ──
function removeItem(el) {
    el.classList.add('removing');
    setTimeout(() => {
        el.style.maxHeight = el.offsetHeight + 'px';
        el.style.overflow  = 'hidden';
        el.style.transition = 'max-height 0.25s ease, padding 0.25s ease, margin 0.25s ease';
        requestAnimationFrame(() => {
            el.style.maxHeight = '0';
            el.style.paddingTop    = '0';
            el.style.paddingBottom = '0';
            el.style.marginTop     = '0';
            el.style.marginBottom  = '0';
        });
        setTimeout(() => { el.remove(); checkVacio(); }, 280);
    }, 260);
}

// ── Marcar como leída (individual) ──
async function marcarLeida(id, btn) {
    btn.disabled = true;
    const data = await notifAjax('marcar_leida', id);
    if (!data || !data.ok) { btn.disabled = false; return; }

    const item = document.getElementById('notif-' + id);
    if (item) {
        item.classList.remove('no-leida');
        item.classList.add('leida');
        item.removeAttribute('data-leida');
        // Quitar el punto azul y el botón de marcar
        const punto = item.querySelector('.punto-azul');
        if (punto) punto.remove();
        btn.remove();
        // Si estamos en filtro no_leidas → remover del DOM también
        if (FILTRO_ACT === 'no_leidas') removeItem(item);
    }
    actualizarBadges(data.no_leidas);
}

// ── Eliminar (individual) ──
async function eliminarNotif(id, btn) {
    btn.disabled = true;
    const item = document.getElementById('notif-' + id);
    const eraNoLeida = item && item.classList.contains('no-leida');

    const data = await notifAjax('eliminar', id);
    if (!data || !data.ok) { btn.disabled = false; return; }

    if (item) removeItem(item);
    decrementarTotal(1);
    if (eraNoLeida) actualizarBadges(data.no_leidas);
}

// ── Acciones bulk ──
async function accionBulk(accion) {
    if (accion === 'limpiar_leidas') {
        const leidas = document.querySelectorAll('#notif-list .notif-item.leida');
        if (leidas.length === 0) return;
        const ok = await ggiConfirm('Eliminar notificaciones', '¿Eliminar todas las notificaciones leídas? Esta acción no se puede deshacer.', { type: 'warning', confirmText: 'Sí, eliminar' });
        if (!ok) return;
    }

    const data = await notifAjax(accion);
    if (!data || !data.ok) return;

    if (accion === 'marcar_todas') {
        document.querySelectorAll('#notif-list .notif-item.no-leida').forEach(item => {
            item.classList.remove('no-leida');
            item.classList.add('leida');
            const punto = item.querySelector('.punto-azul');
            if (punto) punto.remove();
            const btnLeer = item.querySelector('.btn-leer');
            if (btnLeer) btnLeer.remove();
            if (FILTRO_ACT === 'no_leidas') removeItem(item);
        });
        actualizarBadges(0);
    } else if (accion === 'limpiar_leidas') {
        const leidas = document.querySelectorAll('#notif-list .notif-item.leida');
        const cant = leidas.length;
        leidas.forEach(item => removeItem(item));
        decrementarTotal(cant);
        // En filtro 'leidas' también quedan no-leidas visible si las hay, no decrementar badges
    }
}
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
