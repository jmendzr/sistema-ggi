<?php
$page_title  = 'Kanban de envíos';
$active_menu = 'envios';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/envio_notificar.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gestor_logistico','delivery');

$pdo = Database::get();
$buscar     = trim($_GET['q'] ?? '');
$gestor_fil = (int)($_GET['gestor'] ?? 0);

$where  = ["e.estado_envio_id IN (3,5,6)"];
$params = [];

if ($user['rol'] === 'ejecutivo_ventas') {
    $where[]  = '(e.ejecutivo_id = ? OR cl.ejecutivo_id = ?)';
    $params[] = $user['id']; $params[] = $user['id'];
}
if ($user['rol'] === 'gestor_logistico') {
    $where[]  = '(e.agente_id = ? OR e.agente_id IS NULL)';
    $params[] = $user['id'];
}
if ($buscar) {
    $where[]  = '(cl.razon_social LIKE ? OR e.codigo_envio LIKE ? OR e.destinatario LIKE ?)';
    $params   = array_merge($params, ["%$buscar%","%$buscar%","%$buscar%"]);
}
if ($gestor_fil) {
    $where[]  = 'e.agente_id = ?';
    $params[] = $gestor_fil;
}

$sql_where = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT e.id, e.codigo_envio, e.fecha_envio, e.destinatario, e.destinatario_dni,
           e.estado_envio_id, e.agente_id,
           cl.razon_social, cl.ruc,
           ag.nombre AS agencia,
           es.nombre AS estado_nombre, es.color_hex,
           agt.nombre AS agente_nombre, agt.apellido AS agente_apellido,
           DATEDIFF(CURDATE(), e.fecha_envio) AS dias_transit
    FROM envios e
    JOIN clientes cl       ON cl.id = e.cliente_id
    JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    JOIN estados_envio es  ON es.id = e.estado_envio_id
    LEFT JOIN usuarios agt ON agt.id = e.agente_id
    WHERE $sql_where
    ORDER BY e.fecha_envio ASC
");
$stmt->execute($params);
$all_envios = $stmt->fetchAll();

$envio_ids = array_column($all_envios, 'id');
$certs_map = [];
if ($envio_ids) {
    $ph = implode(',', array_fill(0,count($envio_ids),'?'));
    $cq = $pdo->prepare("SELECT ec.envio_id, n.codigo AS norma_codigo FROM envios_certificados ec JOIN certificados c ON c.id=ec.certificado_id JOIN normas_iso n ON n.id=c.norma_iso_id WHERE ec.envio_id IN ($ph)");
    $cq->execute($envio_ids);
    foreach ($cq->fetchAll() as $row) $certs_map[$row['envio_id']][] = $row['norma_codigo'];
}

$columnas = [
    3 => ['nombre'=>'En tránsito', 'color'=>'#F59E0B', 'bg'=>'var(--s-warn-bg)', 'next'=>5, 'items'=>[]],
    5 => ['nombre'=>'Entregado',   'color'=>'#10B981', 'bg'=>'var(--s-ok-bg)', 'next'=>0, 'items'=>[]],
    6 => ['nombre'=>'Devuelto',    'color'=>'#EF4444', 'bg'=>'var(--s-err-bg)', 'next'=>0, 'items'=>[]],
];
foreach ($all_envios as $e) {
    $eid = (int)$e['estado_envio_id'];
    if (isset($columnas[$eid])) $columnas[$eid]['items'][] = $e;
}

$gestores = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='gestor_logistico' ORDER BY nombre")->fetchAll();
$es_gestor = $user['rol'] === 'gestor_logistico';

require_once __DIR__ . '/../../templates/layout.php';
?>

<style>
.kb-toolbar { display:flex; align-items:center; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
.kb-search-wrap { position:relative; flex:1; max-width:300px; }
.kb-search-wrap svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
.kb-search-input { width:100%; padding:8px 12px 8px 36px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); }
.kb-search-input:focus { border-color:var(--azul); }
.filter-select { padding:8px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); cursor:pointer; }

.kanban-board { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; align-items:start; }

.kanban-col { border-radius:14px; overflow:hidden; border:1px solid var(--gris-borde); display:flex; flex-direction:column; min-height:70vh; }
.kanban-col.drag-over .col-body { outline:2px dashed var(--col-color); outline-offset:-4px; background:var(--col-bg); }

.col-header { padding:14px 16px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid rgba(0,0,0,.06); }
.col-title { font-size:13px; font-weight:700; color:var(--col-color); display:flex; align-items:center; gap:7px; }
.col-title::before { content:''; display:inline-block; width:10px; height:10px; border-radius:50%; background:var(--col-color); }
.col-count { background:var(--col-color); color:#fff; font-size:11px; font-weight:700; padding:2px 8px; border-radius:100px; min-width:22px; text-align:center; }

.col-body { padding:12px; flex:1; display:flex; flex-direction:column; gap:10px; min-height:200px; background:var(--bg-surface2); }

.col-empty { border:2px dashed var(--gris-borde); border-radius:10px; padding:24px; text-align:center; color:var(--gris-texto); font-size:12px; font-style:italic; flex:1; display:flex; align-items:center; justify-content:center; }

.kanban-card { background:var(--bg-surface); border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; cursor:grab; transition:box-shadow .15s, transform .15s; user-select:none; position:relative; }
.kanban-card:hover { box-shadow:0 4px 16px rgba(26,58,143,.10); transform:translateY(-1px); }
.kanban-card.dragging { opacity:.5; transform:rotate(1deg); box-shadow:0 8px 32px rgba(0,0,0,.15); }
.kanban-card.card-tracking { cursor:default; }
.kanban-card.card-tracking .agencia-tag { background:var(--s-info-bg,#eef2ff); color:var(--azul,#1A3A8F); }

.card-top { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:6px; }
.card-empresa { font-size:13px; font-weight:700; color:var(--gris-dark); text-decoration:none; line-height:1.3; flex:1; }
.card-empresa:hover { text-decoration:underline; }
.card-dias { font-size:11px; font-weight:700; padding:2px 8px; border-radius:100px; white-space:nowrap; flex-shrink:0; }

.card-tags { display:flex; align-items:center; gap:4px; flex-wrap:wrap; margin-bottom:6px; }
.norma-tag { display:inline-block; background:var(--s-info-bg); color:#475569; border:1px solid #e2e8f0; font-size:10px; font-weight:700; padding:1px 6px; border-radius:5px; }
.agencia-tag { display:inline-block; background:var(--bg-surface2); color:var(--gris-dark); font-size:10px; font-weight:600; padding:1px 6px; border-radius:5px; }

.card-bottom { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:6px; }
.card-dest { font-size:11px; color:var(--gris-texto); flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.card-code { font-family:monospace; font-size:10px; background:var(--bg-surface2); padding:1px 6px; border-radius:5px; color:var(--gris-dark); }

.card-actions { display:flex; align-items:center; gap:6px; margin-top:8px; padding-top:8px; border-top:1px solid var(--bg-surface2); }
.btn-asumir { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; background:#1D9E75; color:#fff; border:none; border-radius:7px; font-size:11px; font-weight:600; cursor:pointer; font-family:'Inter',sans-serif; transition:.15s; }
.btn-asumir:hover { background:#15835f; }
.gestor-avatar { width:22px; height:22px; border-radius:50%; background:#1D9E75; color:#fff; display:flex; align-items:center; justify-content:center; font-size:9px; font-weight:700; flex-shrink:0; }

@keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }

@media(max-width:900px) {
  .kanban-board { grid-template-columns:1fr; }
  .kanban-col { min-height:auto; }
  /* Acordeón: con 3 columnas apiladas, poder colapsar evita scroll eterno.
     El header es tappable (≥44px); chevron indica estado. */
  .col-header { cursor:pointer; min-height:48px; user-select:none; -webkit-tap-highlight-color:transparent; }
  .col-header::after {
    content:''; width:8px; height:8px; flex-shrink:0; margin-left:8px;
    border-right:2px solid var(--col-color); border-bottom:2px solid var(--col-color);
    transform:rotate(45deg); transition:transform .2s;
  }
  .kanban-col.colapsada .col-header::after { transform:rotate(-45deg); }
  .kanban-col.colapsada .col-body { display:none; }
}
</style>

<!-- Page header -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Kanban de envíos</h1>
    <p><?= count($all_envios) ?> envío<?= count($all_envios)!=1?'s':'' ?> en seguimiento</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="<?= APP_URL ?>/modules/envios/index.php" class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
      Vista lista
    </a>
    <?php if (has_role('gerente_general','gerente_operaciones','gestor_logistico','delivery')): ?>
    <a href="<?= APP_URL ?>/modules/envios/crear.php" class="btn btn-primary">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Registrar envío
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Toolbar / filters -->
<div class="kb-toolbar">
  <form method="GET" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
    <div class="kb-search-wrap">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="kb-search-input" placeholder="Buscar empresa, código..." aria-label="Buscar empresa, código..." value="<?= htmlspecialchars($buscar) ?>">
    </div>
    <?php if (!$es_gestor): ?>
    <select name="gestor" class="filter-select" onchange="this.form.submit()">
      <option value="">Todos los gestores</option>
      <?php foreach ($gestores as $g): ?>
      <option value="<?= $g['id'] ?>" <?= $gestor_fil==$g['id']?'selected':'' ?>><?= htmlspecialchars($g['nombre'].' '.$g['apellido']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if ($buscar || $gestor_fil): ?>
    <a href="<?= APP_URL ?>/modules/envios/kanban.php" class="btn btn-outline btn-sm">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<!-- Kanban board -->
<div class="kanban-board">
<?php foreach ($columnas as $estado_id => $col): ?>
  <div class="kanban-col"
       data-estado-id="<?= $estado_id ?>"
       data-next-estado-id="<?= $col['next'] ?>"
       style="--col-color:<?= $col['color'] ?>;--col-bg:<?= $col['bg'] ?>;">
    <div class="col-header" style="background:<?= $col['bg'] ?>;">
      <span class="col-title"><?= htmlspecialchars($col['nombre']) ?></span>
      <span class="col-count" id="count-<?= $estado_id ?>"><?= count($col['items']) ?></span>
    </div>
    <div class="col-body" id="col-<?= $estado_id ?>">
      <?php if (empty($col['items'])): ?>
      <div class="col-empty">Sin envíos en este estado</div>
      <?php else: ?>
      <?php foreach ($col['items'] as $e):
        $dias = (int)$e['dias_transit'];
        $dias_color = $dias <= 3 ? '#10B981' : ($dias <= 7 ? '#F59E0B' : '#EF4444');
        $dias_bg    = $dias <= 3 ? 'var(--s-ok-bg)' : ($dias <= 7 ? 'var(--s-warn-bg)' : 'var(--s-err-bg)');
        $normas     = $certs_map[$e['id']] ?? [];
        $is_final   = in_array($estado_id, [5, 6]);
        // Couriers con tracking: el estado lo maneja la sincronización → no arrastrable ni avanzable a mano.
        $tiene_tracking = gg_courier_con_tracking($e['agencia']);
        $bloq_manual    = $is_final || $tiene_tracking;
        $sin_gestor = empty($e['agente_id']);
        $initials   = $e['agente_nombre'] ? strtoupper(substr($e['agente_nombre'],0,1).substr($e['agente_apellido'],0,1)) : '';
      ?>
      <div class="kanban-card<?= $tiene_tracking ? ' card-tracking' : '' ?>"
           draggable="<?= $bloq_manual ? 'false' : 'true' ?>"
           data-id="<?= $e['id'] ?>"
           data-estado="<?= $estado_id ?>"
           <?= $tiene_tracking ? 'title="Estado automático por tracking del courier"' : '' ?>>
        <div class="card-top">
          <a href="<?= APP_URL ?>/modules/envios/ver.php?id=<?= $e['id'] ?>" class="card-empresa" onclick="event.stopPropagation()"><?= htmlspecialchars($e['razon_social']) ?></a>
          <span class="card-dias" style="background:<?= $dias_bg ?>;color:<?= $dias_color ?>"><?= $dias ?>d</span>
        </div>
        <div class="card-tags">
          <?php foreach ($normas as $n): ?>
          <span class="norma-tag"><?= htmlspecialchars($n) ?></span>
          <?php endforeach; ?>
          <span class="agencia-tag"><?php if ($tiene_tracking): ?><svg width="9" height="9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:2px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><?php endif; ?><?= htmlspecialchars($e['agencia']) ?></span>
        </div>
        <?php if ($e['destinatario'] || $e['codigo_envio']): ?>
        <div class="card-bottom">
          <span class="card-dest"><?= htmlspecialchars($e['destinatario'] ?: '—') ?></span>
          <?php if ($e['codigo_envio']): ?>
          <span class="card-code"><?= htmlspecialchars($e['codigo_envio']) ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="card-actions">
          <?php if ($es_gestor && $sin_gestor && !$is_final): ?>
          <button class="btn-asumir" onclick="asumir(event,<?= $e['id'] ?>)">
            <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Asumir
          </button>
          <?php elseif ($initials): ?>
          <span class="gestor-avatar" title="<?= htmlspecialchars($e['agente_nombre'].' '.$e['agente_apellido']) ?>"><?= $initials ?></span>
          <?php else: ?>
          <span style="font-size:11px;color:var(--gris-texto);font-style:italic;">Sin gestor</span>
          <?php endif; ?>
          <span style="flex:1;"></span>
          <?php if (!$bloq_manual): ?>
          <!-- Menú "Mover a…": única vía táctil (el drag HTML5 no dispara en iOS/Android)
               y además da acceso explícito a TODOS los estados destino (antes "Devuelto"
               solo era alcanzable arrastrando). En desktop convive con el drag. -->
          <div class="ggi-row-menu-wrap">
            <button type="button" class="ggi-row-menu-btn" title="Mover a otro estado" aria-label="Mover a otro estado" aria-haspopup="true" aria-expanded="false">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><polyline points="15 19 12 22 9 19"/><polyline points="19 9 22 12 19 15"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></svg>
            </button>
            <div class="ggi-row-menu">
              <?php foreach ($columnas as $tid => $tcol): if ($tid === $estado_id) continue; ?>
              <button type="button" class="ggi-row-menu-item"
                      onclick="advanceCard(event, <?= $e['id'] ?>, <?= $estado_id ?>, <?= $tid ?>)">
                <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= $tcol['color'] ?>;flex-shrink:0;"></span>
                Mover a <?= htmlspecialchars($tcol['nombre']) ?>
              </button>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<script>
var AJAX_ESTADO  = '<?= APP_URL ?>/modules/envios/ajax_estado.php';
var AJAX_ASUMIR  = '<?= APP_URL ?>/modules/envios/ajax_asumir.php';
var APP_URL_BASE = '<?= APP_URL ?>/modules/envios/ver.php';

// ── Drag and drop ──
var draggedCard = null;

document.querySelectorAll('.kanban-card[draggable="true"]').forEach(card => {
  card.addEventListener('dragstart', e => {
    draggedCard = card;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.id);
  });
  card.addEventListener('dragend', () => {
    card.classList.remove('dragging');
    draggedCard = null;
  });
});

document.querySelectorAll('.kanban-col').forEach(col => {
  col.addEventListener('dragover', e => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    col.classList.add('drag-over');
  });
  col.addEventListener('dragleave', e => {
    if (!col.contains(e.relatedTarget)) col.classList.remove('drag-over');
  });
  col.addEventListener('drop', async e => {
    e.preventDefault();
    col.classList.remove('drag-over');
    if (!draggedCard) return;
    const newEstadoId  = parseInt(col.dataset.estadoId);
    const cardEstadoId = parseInt(draggedCard.dataset.estado);
    if (newEstadoId === cardEstadoId) return;
    await moveCard(draggedCard, cardEstadoId, newEstadoId, col.querySelector('.col-body'));
  });
});

async function moveCard(card, fromEstadoId, toEstadoId, targetBody) {
  const envioId = card.dataset.id;
  try {
    const data = await ggiPost(AJAX_ESTADO, { envio_id: envioId, estado_id: toEstadoId }, { silent: true });
    if (data.ok) {
      // Move DOM
      const fromBody = document.getElementById('col-' + fromEstadoId);
      targetBody.appendChild(card);
      card.dataset.estado = toEstadoId;
      // Update draggable: final states not draggable
      const isFinal = [5,6].includes(toEstadoId);
      card.setAttribute('draggable', isFinal ? 'false' : 'true');
      // Menú "Mover a…": en estado final ya no aplica (mismo criterio que $bloq_manual)
      const menuWrap = card.querySelector('.ggi-row-menu-wrap');
      if (menuWrap && isFinal) menuWrap.remove();
      // Update empty state in from column
      updateEmptyState(fromBody, fromEstadoId);
      updateEmptyState(targetBody, toEstadoId);
      // Update counts
      updateCount(fromEstadoId, -1);
      updateCount(toEstadoId, +1);
      if (typeof showToast==='function') showToast('Estado actualizado.','ok');
    } else {
      if (typeof showToast==='function') showToast(data.msg||'Error al actualizar.','error');
    }
  } catch(err) {
    if (typeof showToast==='function') showToast('Error de conexión.','error');
  }
}

async function advanceCard(e, envioId, fromEstadoId, toEstadoId) {
  e.stopPropagation();
  // stopPropagation impide que el cierre delegado de GGIRowMenu (document click)
  // se dispare → cerrar explícitamente.
  if (typeof GGIRowMenu !== 'undefined') GGIRowMenu.closeAll(null);
  const card = document.querySelector(`.kanban-card[data-id="${envioId}"]`);
  const targetBody = document.getElementById('col-' + toEstadoId);
  if (!card || !targetBody) return;
  await moveCard(card, fromEstadoId, toEstadoId, targetBody);
}

async function asumir(e, envioId) {
  e.stopPropagation();
  const btn = e.currentTarget;
  btn.disabled = true;
  btn.innerHTML = '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>';
  try {
    const data = await ggiPost(AJAX_ASUMIR, { envio_id: envioId }, { silent: true });
    if (data.ok) {
      if (typeof showToast==='function') showToast('Envío asumido.','ok');
      // Actualizar solo el botón en la card sin recargar
      btn.closest('.kanban-btn-asumir, .asumir-wrap') ? btn.closest('.kanban-btn-asumir, .asumir-wrap').remove() : btn.remove();
    } else {
      if (typeof showToast==='function') showToast(data.msg||'No se pudo asumir.','error');
      btn.disabled = false;
    }
  } catch(err) {
    if (typeof showToast==='function') showToast('Error de conexión.','error');
    btn.disabled = false;
  }
}

// ── Acordeón móvil: tocar el header colapsa/expande la columna (solo ≤900px) ──
document.querySelectorAll('.kanban-col .col-header').forEach(head => {
  head.addEventListener('click', () => {
    if (window.innerWidth > 900) return;
    head.closest('.kanban-col').classList.toggle('colapsada');
  });
});

function updateCount(estadoId, delta) {
  const el = document.getElementById('count-' + estadoId);
  if (el) el.textContent = Math.max(0, parseInt(el.textContent) + delta);
}

function updateEmptyState(colBody, estadoId) {
  const cards = colBody.querySelectorAll('.kanban-card');
  let empty = colBody.querySelector('.col-empty');
  if (cards.length === 0 && !empty) {
    const div = document.createElement('div');
    div.className = 'col-empty';
    div.textContent = 'Sin envíos en este estado';
    colBody.appendChild(div);
  } else if (cards.length > 0 && empty) {
    empty.remove();
  }
}
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
