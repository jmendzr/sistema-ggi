<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$pdo      = Database::get();
$buscar   = trim($_GET['q'] ?? '');
$proximas = !empty($_GET['proximas']);  // próximas 60 días pendientes (desde dashboard)

// Multi-selección de resultados: acepta ?filtro[]=v1&filtro[]=v2 O legado ?filtro=v
$_fil_raw    = array_key_exists('filtro', $_GET) ? (array)$_GET['filtro'] : [];
$filtros_sel = array_values(array_filter($_fil_raw, fn($v) => $v !== ''));
$filtro      = count($filtros_sel) === 1 ? $filtros_sel[0] : ''; // compat legado
$page     = max(1, (int)($_GET['page'] ?? 1));
$_pp_opts = [10, 20, 30];
$_pp_req  = (int)($_GET['per_page'] ?? $_SESSION['per_page_list'] ?? 10);
$limit    = in_array($_pp_req, $_pp_opts) ? $_pp_req : 10;
$_SESSION['per_page_list'] = $limit;
$offset   = ($page - 1) * $limit;

$where  = ['a.deleted_at IS NULL', 'c.deleted_at IS NULL', 'cl.deleted_at IS NULL'];
$params = [];

if ($buscar) {
    $where[]  = '(cl.razon_social LIKE ? OR n.codigo LIKE ? OR a.auditor_externo LIKE ?)';
    $params   = array_merge($params, ["%$buscar%", "%$buscar%", "%$buscar%"]);
}
if ($proximas) {
    $where[] = 'a.fecha_programada BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)';
    $where[] = "a.resultado = 'pendiente'";
} elseif (!empty($filtros_sel)) {
    $ph = implode(',', array_fill(0, count($filtros_sel), '?'));
    $where[]  = "a.resultado IN ($ph)";
    $params   = array_merge($params, $filtros_sel);
}

$sql_where = implode(' AND ', $where);

$total = $pdo->prepare("
    SELECT COUNT(*) FROM auditorias a
    JOIN certificados c ON c.id = a.certificado_id
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN normas_iso n ON n.id = c.norma_iso_id
    WHERE $sql_where
");
$total->execute($params);
$total_rows  = $total->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$stmt = $pdo->prepare("
    SELECT a.*,
           cl.id AS cliente_id, cl.razon_social, cl.codigo_interno, cl.ruc,
           n.codigo AS norma_codigo,
           COALESCE(cc_aud.nombre, cc_cert.nombre) AS casa_nombre
    FROM auditorias a
    JOIN certificados c   ON c.id  = a.certificado_id
    JOIN clientes cl      ON cl.id = c.cliente_id
    JOIN normas_iso n     ON n.id  = c.norma_iso_id
    LEFT JOIN casas_certificadoras cc_aud  ON cc_aud.id  = a.casa_certificadora_id
    LEFT JOIN casas_certificadoras cc_cert ON cc_cert.id = c.casa_certificadora_id
    WHERE $sql_where
    ORDER BY a.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$auditorias = $stmt->fetchAll();

$resultado_map = [
    'pendiente'         => ['label' => 'Pendiente',         'color' => 'var(--tx2)', 'bg' => 'var(--s-info-bg)', 'fg' => '#475569'],
    'aprobado'          => ['label' => 'Aprobado',          'color' => '#10B981', 'bg' => 'var(--s-ok-bg)', 'fg' => 'var(--s-ok-tx)'],
    'con_observaciones' => ['label' => 'Con observaciones', 'color' => '#F59E0B', 'bg' => 'var(--s-warn-bg)', 'fg' => 'var(--s-warn-tx)'],
    'no_conforme'       => ['label' => 'No conforme',       'color' => '#EF4444', 'bg' => 'var(--s-err-bg)', 'fg' => '#c53030'],
    'reprogramado'      => ['label' => 'Reprogramado',      'color' => '#8B5CF6', 'bg' => 'var(--s-purple-bg)', 'fg' => '#6d28d9'],
];

$tipo_map = [
    'seguimiento_1' => 'Seguimiento 1',
    'seguimiento_2' => 'Seguimiento 2',
    'renovacion'    => 'Renovación',
];

$page_title  = 'Auditorías';
$active_menu = 'auditorias';
$user        = auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .toolbar { display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  .search-wrap { position:relative; flex:1; max-width:360px; }
  .search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .search-input { width:100%; padding:9px 12px 9px 38px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); }
  .search-input:focus { border-color:var(--azul); }
  .filter-select { padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); color:var(--gris-dark); cursor:pointer; }
  .filter-select:focus { border-color:var(--azul); }
  .table-wrap { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  /* .data-table base → canónico en design-system.css */
  .badge { display:inline-block; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:600; white-space:nowrap; }
  /* Light mode — colores por resultado */
  .badge[data-resultado="pendiente"]         { background:var(--s-info-bg); color:#475569; }
  .badge[data-resultado="aprobado"]          { background:var(--s-ok-bg); color:var(--s-ok-tx); }
  .badge[data-resultado="con_observaciones"] { background:var(--s-warn-bg); color:var(--s-warn-tx); }
  .badge[data-resultado="no_conforme"]       { background:var(--s-err-bg); color:#c53030; }
  .badge[data-resultado="reprogramado"]      { background:var(--s-purple-bg); color:#6d28d9; }
  /* Dark mode */
  :root[data-tema="oscuro"] .badge[data-resultado="pendiente"]         { background:#1e293b !important; color:#94a3b8 !important; }
  :root[data-tema="oscuro"] .badge[data-resultado="aprobado"]          { background:#14532d !important; color:#86efac !important; }
  :root[data-tema="oscuro"] .badge[data-resultado="con_observaciones"] { background:#451a03 !important; color:#fcd34d !important; }
  :root[data-tema="oscuro"] .badge[data-resultado="no_conforme"]       { background:#450a0a !important; color:#fca5a5 !important; }
  :root[data-tema="oscuro"] .badge[data-resultado="reprogramado"]      { background:#2e1065 !important; color:#c4b5fd !important; }
  /* .btn-ic, .btn-ic-blue, .empty-state → canónicos en design-system.css */
  .alerta-vence { color:var(--s-warn-tx); font-size:11px; font-weight:600; }
  /* estado-sel: global en layout_end.php */
  /* Paginación: estilos en layout_end.php */
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Auditorías</h1>
    <p><?= $total_rows ?> auditoría<?= $total_rows!=1?'s':'' ?> registrada<?= $total_rows!=1?'s':'' ?></p>
  </div>
</div>

<div class="toolbar">
  <?php if ($proximas): ?>
  <div style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;">
    <span style="display:inline-flex;align-items:center;gap:6px;background:var(--s-info-bg);color:var(--s-info-tx);border:1px solid #bae6fd;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Próximas auditorías (60 días)
    </span>
    <a href="?" class="btn btn-outline btn-sm">Ver todas</a>
  </div>
  <?php else: ?>
  <?php $_nfiltros = (!empty($filtros_sel) ? 1 : 0); ?>
  <form method="GET" class="<?= $_nfiltros ? 'filtros-open' : '' ?>" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
    <div class="search-wrap">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="search-input" placeholder="Buscar cliente, norma, auditor..." aria-label="Buscar cliente, norma, auditor..." value="<?= sanitize($buscar) ?>" oninput="ggiSearch('auditorias-q',this,10000)" onkeydown="if(event.key==='Enter'){event.preventDefault();ggiSearch('auditorias-q',this,0)}">
    </div>
    <button type="button" class="ggi-filtros-toggle" onclick="this.closest('form').classList.toggle('filtros-open')" aria-label="Mostrar u ocultar filtros">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Filtros<?php if ($_nfiltros): ?> <span class="ggi-filtros-badge"><?= $_nfiltros ?></span><?php endif; ?>
    </button>
    <div class="ggi-filtros">
    <?php
    $_aud_resultados = [
      'pendiente'         => ['label'=>'Pendiente',         'cls'=>'is-warn'],
      'aprobado'          => ['label'=>'Aprobado',          'cls'=>'is-ok'],
      'observado'         => ['label'=>'Con observaciones', 'cls'=>'is-info'],
      'no_aprobado'       => ['label'=>'No aprobado',       'cls'=>'is-err'],
    ];
    $_aud_lbl = empty($filtros_sel) ? 'Todos los resultados'
      : implode(', ', array_map(fn($v) => $_aud_resultados[$v]['label'] ?? $v, $filtros_sel));
    ?>
    <div class="ms-drop" id="ms-aud-filtro">
      <button type="button" class="ms-drop-btn" onclick="msDrop.toggle('ms-aud-filtro')" aria-haspopup="listbox" aria-label="Filtrar por resultado">
        <span class="ms-drop-lbl <?= empty($filtros_sel)?'is-placeholder':'' ?>"><?= htmlspecialchars($_aud_lbl) ?></span>
        <?php if (!empty($filtros_sel)): ?><span class="ms-drop-count"><?= count($filtros_sel) ?></span><?php endif; ?>
        <svg class="ms-drop-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="ms-drop-menu" hidden>
        <?php foreach ($_aud_resultados as $_val => $_cfg): ?>
        <label class="ms-drop-item">
          <input type="checkbox" name="filtro[]" value="<?= $_val ?>"
                 <?= in_array($_val, $filtros_sel) ? 'checked' : '' ?>
                 onchange="msDrop.change('ms-aud-filtro', this)">
          <span class="ms-drop-dot <?= $_cfg['cls'] ?>"></span>
          <span><?= $_cfg['label'] ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    </div><!-- /.ggi-filtros -->
    <?php if ($buscar || !empty($filtros_sel)): ?>
      <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
    <?php endif; ?>
  </form>
  <?php endif; ?>
</div>

<div class="table-wrap">
  <?php if (empty($auditorias)): ?>
  <div class="empty-state">
    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:0.25;"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    <h3 style="font-size:15px;color:var(--gris-dark);margin-bottom:4px;"><?= ($buscar||!empty($filtros_sel)||$proximas) ? 'Sin resultados' : 'Sin auditorías' ?></h3>
    <p style="font-size:13px;"><?= $proximas ? 'No hay auditorías pendientes programadas en los próximos 60 días.' : (($buscar||!empty($filtros_sel)) ? 'Prueba con otros filtros.' : 'Las auditorías se generan automáticamente al registrar un certificado.') ?></p>
    <?php if ($buscar || !empty($filtros_sel) || $proximas): ?>
    <a href="<?= APP_URL ?>/modules/auditorias/index.php" class="btn btn-outline" style="margin-top:16px;">Limpiar filtros</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="data-table">
    <thead>
      <tr>
        <th scope="col">Empresa</th>
        <th scope="col">Norma</th>
        <th scope="col">Tipo</th>
        <th scope="col">Fecha programada</th>
        <th scope="col">Fecha realizada</th>
        <th scope="col">Casa certif.</th>
        <th scope="col" style="text-align:center;">Resultado</th>
        <th scope="col">Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($auditorias as $a):
        $res    = $resultado_map[$a['resultado']] ?? $resultado_map['pendiente'];
        $dias   = dias_hasta($a['fecha_programada']);
        $vence_pronto = $a['resultado'] === 'pendiente' && $dias >= 0 && $dias <= 30;
        $vencida      = $a['resultado'] === 'pendiente' && $dias < 0;
      ?>
      <tr>
        <td>
          <div class="av-row">
            <span class="av-chip" data-name="<?= sanitize($a['razon_social']) ?>"></span>
            <div class="av-name">
              <a class="row-link" href="ver.php?id=<?= $a['id'] ?>"><span class="txt-ellipsis" title="<?= sanitize($a['razon_social']) ?>"><?= sanitize($a['razon_social']) ?></span></a>
              <div style="font-size:11px;color:var(--gris-texto);"><?= ggi_copy($a['ruc'] ?? '') ?></div>
            </div>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
            <?= ggi_tags([$a['norma_codigo']], ['noun' => 'norma']) ?>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#8B5CF6" stroke-width="2" style="flex-shrink:0;"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            <?= sanitize($tipo_map[$a['tipo']] ?? $a['tipo']) ?>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#6B7280" stroke-width="2" style="flex-shrink:0;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <div>
              <?= formato_fecha($a['fecha_programada']) ?>
              <?php if ($vence_pronto): ?>
              <div class="alerta-vence">⚠ En <?= $dias ?> días</div>
              <?php elseif ($vencida): ?>
              <div style="color:#dc2626;font-size:11px;font-weight:600;">⚠ Vencida hace <?= abs($dias) ?> días</div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td><?= $a['fecha_realizada'] ? formato_fecha($a['fecha_realizada']) : '<span style="color:var(--gris-texto)">—</span>' ?></td>
        <td><?= $a['casa_nombre'] ? sanitize($a['casa_nombre']) : '<span style="color:var(--gris-texto)">—</span>' ?></td>
        <td style="text-align:center;">
          <?php if (has_role('gerente_general','gerente_operaciones')): ?>
          <div class="estado-sel"
               data-id="<?= $a['id'] ?>"
               data-value="<?= htmlspecialchars($a['resultado']) ?>"
               data-color="<?= htmlspecialchars($res['color']) ?>"
               data-module="auditorias"
               role="button" tabindex="0">
            <span class="estado-sel-label"><?= $res['label'] ?></span>
            <div class="estado-sel-menu" hidden>
              <div class="estado-sel-option" data-value="pendiente"         data-color="#6B7280" style="--opt-color:#6B7280">Pendiente</div>
              <div class="estado-sel-option" data-value="aprobado"          data-color="#059669" style="--opt-color:#059669">Aprobado</div>
              <div class="estado-sel-option" data-value="con_observaciones" data-color="#D97706" style="--opt-color:#D97706">Con observaciones</div>
              <div class="estado-sel-option" data-value="no_conforme"       data-color="#DC2626" style="--opt-color:#DC2626">No conforme</div>
              <div class="estado-sel-option" data-value="reprogramado"      data-color="#7C3AED" style="--opt-color:#7C3AED">Reprogramado</div>
            </div>
          </div>
          <?php else: ?>
          <span class="badge" data-resultado="<?= htmlspecialchars($a['resultado']) ?>">
            <?= $res['label'] ?>
          </span>
          <?php endif; ?>
        </td>
        <td>
          <div class="action-btns" style="gap:4px;">
            <a href="<?= APP_URL ?>/modules/auditorias/ver.php?id=<?= $a['id'] ?>" class="btn-ic btn-ic-blue" title="Ver / Registrar resultado">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <a href="<?= APP_URL ?>/modules/auditorias/ver.php?id=<?= $a['id'] ?>&editar=1" class="btn-ic btn-ic-blue" title="Editar">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </a>
            <?php if (has_role('gerente_general','gerente_operaciones')): ?>
            <div class="ggi-row-menu-wrap">
              <button type="button" class="ggi-row-menu-btn" title="Más acciones" aria-label="Más acciones" aria-haspopup="true" aria-expanded="false">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
              </button>
              <div class="ggi-row-menu">
                <button type="button" class="ggi-row-menu-item danger"
                  onclick="ggiEliminarAuditoria(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes(formato_fecha($a['fecha_programada']))) ?>', '<?= $a['resultado'] ?>')">
                  <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6m4-6v6"/><path d="M9 6V4h6v2"/></svg>
                  Eliminar auditoría
                </button>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($total_rows > 0): ?>
    <?= ggi_pager($total_rows, $page, $limit, [
        'per_page_options' => [10, 20, 30],
        'param_page'       => 'page',
        'noun'             => 'auditoría',
        'noun_plural'      => 'auditorías',
    ]) ?>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function ggiEliminarAuditoria(id, fecha, resultado) {
  const res = {pendiente:'Pendiente',aprobado:'Aprobado',con_observaciones:'Con observaciones',no_conforme:'No conforme',reprogramado:'Reprogramado'};
  ggiConfirmarEliminar({
    titulo:      'Eliminar auditoría',
    mensaje:     'Se eliminará la auditoría del <strong>' + fecha + '</strong> (resultado: ' + (res[resultado]||resultado) + ').',
    advertencia: 'La auditoría desaparecerá del listado. Podrás deshacerla durante unos segundos desde el aviso.',
    reversible:  false,
    labelConfirmar: 'Sí, eliminar',
    onConfirmar: async function() {
      const fd = new FormData();
      fd.append('auditoria_id', id);
      fd.append('_csrf', document.querySelector('[name="_csrf"]')?.value || '');
      const r    = await fetch('<?= APP_URL ?>/modules/auditorias/ajax_eliminar.php', { method:'POST', headers:{'X-CSRF-TOKEN':window.CSRF_TOKEN||''}, body: fd });
      const data = await r.json();
      if (data.ok) {
        if (typeof ggiToastUndo === 'function') ggiToastUndo('auditorias', id, data.msg);
        else if (typeof showToast === 'function') showToast(data.msg, 'ok');
        const btn = document.querySelector('[onclick*="ggiEliminarAuditoria(' + id + ',"]');
        if (btn) btn.closest('tr')?.remove();
        if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
      } else {
        if (typeof showToast === 'function') showToast(data.msg || 'No se pudo eliminar.', 'error');
      }
    }
  });
}

document.addEventListener('estado-sel-change', async function(e) {
  if (e.detail.module !== 'auditorias') return;
  const d = e.detail;
  try {
    const r = await fetch('<?= APP_URL ?>/modules/auditorias/ajax_resultado.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':window.CSRF_TOKEN||''},
      body: `aud_id=${d.id}&resultado=${d.value}`
    });
    const data = await r.json();
    if (data.ok) {
      d.el.setAttribute('data-color', d.color);
      d.el.setAttribute('data-value', d.value);
      const lbl = d.el.querySelector('.estado-sel-label');
      if (lbl) lbl.textContent = d.label;
      if (typeof window.ggiApplySelectColors === 'function') window.ggiApplySelectColors(d.el.closest('tbody') || document);
      if (typeof showToast === 'function') showToast('Resultado actualizado.', 'ok');
    } else {
      if (typeof showToast === 'function') showToast(data.msg || 'Error.', 'error');
    }
  } catch(e) {
    if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
  }
});
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
<style>
.mobile-icon { display:none; }
@media (max-width: 768px) {
  .mobile-icon { display:inline-block; }
  .data-table thead { display:none; }
  .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; }
  .data-table tr {
    background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde);
    margin-bottom:10px; padding:14px 16px;
  }
  .data-table tr:hover td { background:transparent; }
  .data-table td { padding:5px 0; border-bottom:none; font-size:13px; }
  .data-table td:nth-child(5),
  .data-table td:nth-child(6) { display:none; }
  .data-table td:last-child { margin-top:10px; padding-top:10px; border-top:1px solid var(--bg-surface2); }
  .action-btns { gap:8px; }
  .btn-ic { width:36px; height:36px; }
  .toolbar { flex-direction:column; align-items:stretch; }
  .toolbar form { flex-direction:column; flex-wrap:nowrap; }
  .search-wrap { max-width:100%; }
  .filter-select { width:100%; }
  .estado-sel { max-width:180px !important; }
  .estado-sel-menu { left:0; transform:none; }
}
</style>
