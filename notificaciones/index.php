<?php
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();

$pdo = Database::get();
$uid = auth_user()['id'];

// ── Acciones POST — ANTES de incluir el layout (que ya envía HTML) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    // Preservar el filtro activo al redirigir
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
$per_page = 20;
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

$no_leidas = (int)$pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leida=0")->execute([$uid]) ? $pdo->query("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=$uid AND leida=0")->fetchColumn() : 0;

// Iconos y colores por tipo
function notif_meta(string $tipo): array {
    $map = [
        'cert_por_vencer'    => ['color' => '#f59e0b', 'bg' => '#fff7e6', 'icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="12 6 12 12 16 14"/>',  'label' => 'Certificado por vencer'],
        'cert_vencido'       => ['color' => '#ef4444', 'bg' => '#fee2e2', 'icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>', 'label' => 'Certificado vencido'],
        'auditoria_proxima'  => ['color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',  'label' => 'Auditoría próxima'],
        'envio_sin_confirmar'=> ['color' => '#8b5cf6', 'bg' => '#f5f3ff', 'icon' => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>', 'label' => 'Envío sin confirmar'],
        'cotizacion_vencida' => ['color' => '#10b981', 'bg' => '#d1fae5', 'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>',  'label' => 'Cotización por vencer'],
    ];
    return $map[$tipo] ?? ['color' => '#6b7a99', 'bg' => '#f0f2f8', 'icon' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>', 'label' => 'Sistema'];
}

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
  border:1.5px solid var(--gris-borde); background:#fff; color:var(--gris-texto);
  text-decoration:none; cursor:pointer; transition:all 0.15s;
}
.filtro-btn:hover, .filtro-btn.active { border-color:var(--azul); background:var(--azul-light); color:var(--azul); }
.filtro-btn .cnt { margin-left:5px; background:var(--azul); color:#fff; border-radius:100px; font-size:10px; padding:1px 6px; }
.btn-accion {
  display:inline-flex; align-items:center; gap:6px;
  padding:7px 14px; border-radius:8px; font-size:12px; font-weight:600;
  border:1.5px solid var(--gris-borde); background:#fff; color:var(--gris-texto);
  cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; transition:all 0.15s;
}
.btn-accion:hover { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }
.btn-accion.danger:hover { border-color:var(--red); color:var(--red); background:#fee2e2; }

.notif-list { display:flex; flex-direction:column; gap:0; background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
.notif-item {
  display:flex; align-items:flex-start; gap:14px;
  padding:16px 20px; border-bottom:1px solid #f0f2f8;
  transition:background 0.15s; position:relative;
}
.notif-item:last-child { border-bottom:none; }
.notif-item.no-leida { background:#fafbfe; }
.notif-item.no-leida::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--azul); border-radius:2px 0 0 2px; }
.notif-item:hover { background:#f7f9ff; }

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
  background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center;
  color:var(--gris-texto); transition:all 0.15s;
}
.notif-btn-sm:hover { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }
.notif-btn-sm.del:hover { border-color:var(--red); color:var(--red); background:#fee2e2; }

.notif-empty { padding:48px; text-align:center; color:var(--gris-texto); }
.notif-empty svg { opacity:0.2; display:block; margin:0 auto 12px; }

.paginacion { display:flex; justify-content:center; gap:6px; margin-top:20px; flex-wrap:wrap; }
.pag-btn { padding:7px 13px; border-radius:8px; font-size:12px; font-weight:600; border:1.5px solid var(--gris-borde); background:#fff; color:var(--gris-texto); text-decoration:none; transition:all 0.15s; }
.pag-btn:hover, .pag-btn.active { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }
.pag-btn.disabled { opacity:0.4; pointer-events:none; }

.stats-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; margin-bottom:20px; }
.stat-mini { background:#fff; border:1px solid var(--gris-borde); border-radius:10px; padding:14px 16px; text-align:center; }
.stat-mini .val { font-family:'Outfit',sans-serif; font-size:22px; font-weight:700; color:var(--gris-dark); }
.stat-mini .lbl { font-size:11px; color:var(--gris-texto); margin-top:2px; }
</style>



<?php
// Stats rápidas
$stats_tipos = $pdo->query("SELECT tipo, COUNT(*) as cnt FROM notificaciones WHERE usuario_id=$uid AND leida=0 GROUP BY tipo")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<?php if ($no_leidas > 0): ?>
<div class="stats-row">
  <?php foreach (['cert_por_vencer'=>'Por vencer','cert_vencido'=>'Vencidos','auditoria_proxima'=>'Auditorías','envio_sin_confirmar'=>'Envíos','cotizacion_vencida'=>'Cotizaciones'] as $k => $lbl): ?>
    <?php if (!empty($stats_tipos[$k])): $m = notif_meta($k); ?>
    <div class="stat-mini" style="border-left:3px solid <?= $m['color'] ?>;">
      <div class="val" style="color:<?= $m['color'] ?>"><?= $stats_tipos[$k] ?></div>
      <div class="lbl"><?= $lbl ?></div>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="notif-toolbar">
  <div class="notif-filtros">
    <a href="?filtro=todas"     class="filtro-btn <?= $filtro==='todas'?'active':'' ?>">Todas <span class="cnt"><?= $total ?></span></a>
    <a href="?filtro=no_leidas" class="filtro-btn <?= $filtro==='no_leidas'?'active':'' ?>">Sin leer <span class="cnt" style="background:var(--red)"><?= $no_leidas ?></span></a>
    <a href="?filtro=leidas"    class="filtro-btn <?= $filtro==='leidas'?'active':'' ?>">Leídas</a>
  </div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;">
    <?php if ($no_leidas > 0): ?>
    <form method="POST" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="accion"  value="marcar_todas">
      <input type="hidden" name="filtro"  value="<?= htmlspecialchars($filtro) ?>">
      <button type="submit" class="btn-accion">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Marcar todas leídas
      </button>
    </form>
    <?php endif; ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar todas las notificaciones leídas?')">
      <?= csrf_field() ?>
      <input type="hidden" name="accion"  value="limpiar_leidas">
      <input type="hidden" name="filtro"  value="<?= htmlspecialchars($filtro) ?>">
      <button type="submit" class="btn-accion danger">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
        Limpiar leídas
      </button>
    </form>
  </div>
</div>

<div class="notif-list">
  <?php if (empty($notifs)): ?>
  <div class="notif-empty">
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
  <div class="notif-item <?= $leida ? 'leida' : 'no-leida' ?>">
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
          <span style="width:6px;height:6px;border-radius:50%;background:var(--azul);display:inline-block;"></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="notif-acciones">
      <?php if ($n['url_accion']): ?>
      <a href="<?= htmlspecialchars($n['url_accion']) ?>" class="notif-btn-sm" title="Ver detalle">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      </a>
      <?php endif; ?>
      <?php if (!$leida): ?>
      <form method="POST" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="marcar_leida">
        <input type="hidden" name="id" value="<?= $n['id'] ?>">
        <button type="submit" class="notif-btn-sm" title="Marcar como leída">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        </button>
      </form>
      <?php endif; ?>
      <form method="POST" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="eliminar">
        <input type="hidden" name="id" value="<?= $n['id'] ?>">
        <button type="submit" class="notif-btn-sm del" title="Eliminar">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<div class="paginacion">
  <a href="?filtro=<?= $filtro ?>&page=<?= $page-1 ?>" class="pag-btn <?= $page<=1?'disabled':'' ?>">← Anterior</a>
  <?php for ($i = max(1,$page-2); $i <= min($pages,$page+2); $i++): ?>
  <a href="?filtro=<?= $filtro ?>&page=<?= $i ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
  <?php endfor; ?>
  <a href="?filtro=<?= $filtro ?>&page=<?= $page+1 ?>" class="pag-btn <?= $page>=$pages?'disabled':'' ?>">Siguiente →</a>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
