<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gestor_logistico');

$pdo = Database::get();

$buscar   = trim($_GET['q'] ?? '');
$casa_sel = array_values(array_filter(array_map('intval', (array)($_GET['casa'] ?? [])), fn($v) => $v > 0));
$page     = max(1, (int)($_GET['page'] ?? 1));
$_pp_opts  = [10, 20, 30];
$_pp_req   = (int)($_GET['per_page'] ?? $_SESSION['per_page_list'] ?? 10);
$limit     = in_array($_pp_req, $_pp_opts) ? $_pp_req : 10;
$_SESSION['per_page_list'] = $limit;
$offset    = ($page - 1) * $limit;

// Multi-selección de estados: acepta ?estado[]=v1&estado[]=v2 O legado ?estado=v
$_est_raw    = array_key_exists('estado', $_GET) ? (array)$_GET['estado'] : [];
$estados_sel = array_values(array_filter($_est_raw, fn($v) => $v !== ''));
// Backward-compat: ?estado=vigente → ['vigente']

$where  = [
    '1=1',
    'c.deleted_at IS NULL',   // Excluir certificados eliminados
    'cl.deleted_at IS NULL',  // ... y de empresas eliminadas
    // ... y los generados por una propuesta que luego fue eliminada
    '(c.cotizacion_id IS NULL OR EXISTS (SELECT 1 FROM cotizaciones ct WHERE ct.id = c.cotizacion_id AND ct.deleted_at IS NULL))',
];
$params = [];

// Ejecutivo_ventas ve certificados de clientes donde es ejecutivo,
// responsable actual o captador original.
if ($user['rol'] === 'ejecutivo_ventas') {
    $where[]  = '(cl.ejecutivo_id = ? OR cl.responsible_user_id = ? OR cl.created_by_user_id = ?)';
    $params[] = $user['id'];
    $params[] = $user['id'];
    $params[] = $user['id'];
}

if ($buscar) {
    $where[]  = '(cl.razon_social LIKE ? OR cl.ruc LIKE ? OR n.codigo LIKE ? OR c.numero_certificado LIKE ?)';
    $params   = array_merge($params, ["%$buscar%","%$buscar%","%$buscar%","%$buscar%"]);
}
if (!empty($estados_sel)) {
    $ph = implode(',', array_fill(0, count($estados_sel), '?'));
    $where[]  = "c.estado IN ($ph)";
    $params   = array_merge($params, $estados_sel);
}
if ($casa_sel) {
    $ph = implode(',', array_fill(0, count($casa_sel), '?'));
    $where[]  = "c.casa_certificadora_id IN ($ph)";
    $params   = array_merge($params, $casa_sel);
}

// Lista de casas para el filtro
$casas_lista = $pdo->query("SELECT id, nombre FROM casas_certificadoras WHERE activo=1 ORDER BY nombre")->fetchAll();

$sql_where = implode(' AND ', $where);

$total     = $pdo->prepare("SELECT COUNT(*) FROM certificados c JOIN clientes cl ON cl.id=c.cliente_id JOIN normas_iso n ON n.id=c.norma_iso_id WHERE $sql_where");
$total->execute($params);
$total_rows  = $total->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$stmt = $pdo->prepare("
    SELECT c.*, cl.razon_social, cl.codigo_interno, cl.ruc,
           n.codigo AS norma_codigo, n.nombre AS norma_nombre,
           cc.nombre AS casa_nombre,
           DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_vencimiento
    FROM certificados c
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN normas_iso n ON n.id = c.norma_iso_id
    LEFT JOIN casas_certificadoras cc ON cc.id = c.casa_certificadora_id
    WHERE $sql_where
    ORDER BY c.created_at DESC, c.id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$certificados = $stmt->fetchAll();

$page_title  = 'Certificados';
$active_menu = 'certificados';
$user        = auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  .search-wrap { position:relative; flex:1; max-width:360px; }
  .search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .search-input { width:100%; padding:9px 12px 9px 38px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); }
  .search-input:focus { border-color:var(--azul); }
  .filter-select { padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); }
  .table-wrap { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  /* .data-table, .empty-state, .action-btns, .btn-ic, .btn-ic-* → canónicos en design-system.css */
  /* badge-*, dias-chip: globales en layout_end.php (dark mode incluido) */
  .dias-chip { font-size:11px; font-weight:600; border-radius:100px; }
  /* cert-estado-select → reemplazado por .estado-sel global */
  /* ── Bulk checkbox columns ── */
  .th-chk, .td-chk { width:36px; padding:8px 4px 8px 14px !important; }
  .row-chk, .bulk-chk { width:15px; height:15px; cursor:pointer; accent-color:var(--ac,#1d4ed8); }
  @media (max-width:768px) {
    .data-table thead { display:none; }
    .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; }
    .data-table tr { background:var(--bg-surface); border-radius:10px; border:1px solid var(--gris-borde); margin-bottom:10px; padding:14px 16px; }
    .data-table td { padding:3px 0; border:none; }
    .action-btns { margin-top:10px; padding-top:10px; border-top:1px solid var(--bg-surface2); }
    .toolbar { flex-direction:column; align-items:stretch; }
    .search-wrap { max-width:100%; }
  }
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Certificados</h1>
    <p><?= $total_rows ?> certificado<?= $total_rows != 1 ? 's' : '' ?> registrado<?= $total_rows != 1 ? 's' : '' ?></p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <?php $_exp_qs = 'modulo=certificados&q='.urlencode($buscar); foreach ($casa_sel as $_ca) $_exp_qs .= '&casa[]='.(int)$_ca; foreach ($estados_sel as $_es) $_exp_qs .= '&estado[]='.urlencode($_es); ?>
    <a href="<?= APP_URL ?>/modules/exportar.php?<?= $_exp_qs ?>"
       class="btn btn-outline btn-sm" title="Exportar a CSV/Excel">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar
    </a>
    <?php if (has_role('gerente_general','gerente_operaciones')): ?>
    <button type="button" class="btn btn-primary" onclick="ggiOpenForm('<?= APP_URL ?>/modules/certificados/crear.php','Nuevo certificado')">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nuevo certificado
    </button>
    <?php endif; ?>
  </div>
</div>

<?php $_nfiltros = (!empty($estados_sel) ? 1 : 0) + (!empty($casa_sel) ? 1 : 0); ?>
<div class="toolbar">
  <form method="GET" class="<?= $_nfiltros ? 'filtros-open' : '' ?>" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
    <div class="search-wrap">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="search-input" placeholder="Buscar por cliente, norma, número..." aria-label="Buscar por cliente, norma, número..." value="<?= sanitize($buscar) ?>" oninput="ggiSearch('certificados-q',this,10000)" onkeydown="if(event.key==='Enter'){event.preventDefault();ggiSearch('certificados-q',this,0)}">
    </div>
    <button type="button" class="ggi-filtros-toggle" onclick="this.closest('form').classList.toggle('filtros-open')" aria-label="Mostrar u ocultar filtros">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Filtros<?php if ($_nfiltros): ?> <span class="ggi-filtros-badge"><?= $_nfiltros ?></span><?php endif; ?>
    </button>
    <div class="ggi-filtros">
    <?php
    $_cert_estados = [
      'vigente'    => ['label'=>'Vigente',     'cls'=>'is-ok'],
      'por_vencer' => ['label'=>'Por vencer',  'cls'=>'is-warn'],
      'vencido'    => ['label'=>'Vencido',      'cls'=>'is-err'],
      'en_proceso' => ['label'=>'En proceso',   'cls'=>'is-info'],
      'suspendido' => ['label'=>'Suspendido',   'cls'=>'is-muted'],
    ];
    $_cert_lbl = empty($estados_sel) ? 'Todos los estados'
      : (count($estados_sel) <= 2
          ? implode(', ', array_map(fn($v) => $_cert_estados[$v]['label'] ?? $v, $estados_sel))
          : implode(', ', array_map(fn($v) => $_cert_estados[$v]['label'] ?? $v, array_slice($estados_sel,0,2))).' +' .(count($estados_sel)-2).' más');
    ?>
    <div class="ms-drop" id="ms-cert-estado">
      <button type="button" class="ms-drop-btn" onclick="msDrop.toggle('ms-cert-estado')" aria-haspopup="listbox" aria-label="Filtrar por estado">
        <span class="ms-drop-lbl <?= empty($estados_sel)?'is-placeholder':'' ?>"><?= htmlspecialchars($_cert_lbl) ?></span>
        <?php if (!empty($estados_sel)): ?><span class="ms-drop-count"><?= count($estados_sel) ?></span><?php endif; ?>
        <svg class="ms-drop-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="ms-drop-menu" hidden id="ms-cert-estado-menu">
        <?php foreach ($_cert_estados as $_val => $_cfg): ?>
        <label class="ms-drop-item">
          <input type="checkbox" name="estado[]" value="<?= $_val ?>"
                 <?= in_array($_val, $estados_sel) ? 'checked' : '' ?>
                 onchange="msDrop.change('ms-cert-estado', this)">
          <span class="ms-drop-dot <?= $_cfg['cls'] ?>"></span>
          <span><?= $_cfg['label'] ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
      $_casa_opts = [];
      foreach ($casas_lista as $ca) $_casa_opts[(string)$ca['id']] = $ca['nombre'];
      echo ggi_msdrop('ms-cert-casa', 'casa', $_casa_opts, $casa_sel, ['placeholder' => 'Todas las casas', 'aria' => 'Filtrar por casa certificadora']);
    ?>
    </div><!-- /.ggi-filtros -->
    <?php if ($buscar || !empty($estados_sel) || $casa_sel): ?>
      <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($certificados)): ?>
  <div class="empty-state">
    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:0.25;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
    <h3 style="font-size:15px;color:var(--gris-dark);margin-bottom:4px;"><?= $buscar ? 'Sin resultados' : 'Aún no hay certificados' ?></h3>
    <p style="font-size:13px;">
      <?= $buscar ? 'Prueba con otro término.' : 'Registra el primer certificado.' ?>
    </p>
    <?php if ($buscar || !empty($estados_sel) || $casa_sel): ?>
    <a href="<?= APP_URL ?>/modules/certificados/index.php" class="btn btn-outline" style="margin-top:16px;">Limpiar filtros</a>
    <?php else: ?>
    <button type="button" class="btn btn-primary" style="margin-top:16px;" onclick="ggiOpenForm('<?= APP_URL ?>/modules/certificados/crear.php','Nuevo certificado')">Registrar certificado</button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <?php if (has_role('gerente_general','gerente_operaciones')): ?>
        <th scope="col" class="th-chk"><input type="checkbox" id="chk-all-cert" class="bulk-chk" aria-label="Seleccionar todo" title="Seleccionar todo"></th>
        <?php endif; ?>
        <th scope="col">Empresa</th>
        <th scope="col">Norma</th>
        <th scope="col">Casa certif.</th>
        <th scope="col">N° Certificado</th>
        <th scope="col">Emisión</th>
        <th scope="col">Vencimiento</th>
        <th scope="col" style="text-align:center;">Estado</th>
        <th scope="col">Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($certificados as $c): ?>
      <?php
        $dias = (int)$c['dias_vencimiento'];
        // Colores alineados al design system global
        $estado_color_map = [
          'vigente'    => '#059669',
          'por_vencer' => '#D97706',
          'vencido'    => '#DC2626',
          'en_proceso' => '#1D4ED8',
          'suspendido' => '#6B7280',
        ];
        $estado_color = $estado_color_map[$c['estado']] ?? '#6B7280';
        $label_estado_map = [
          'vigente'    => 'Vigente',
          'por_vencer' => 'Por vencer',
          'vencido'    => 'Vencido',
          'en_proceso' => 'En proceso',
          'suspendido' => 'Suspendido',
        ];
        $label_estado = $label_estado_map[$c['estado']] ?? $c['estado'];
      ?>
      <tr>
        <?php if (has_role('gerente_general','gerente_operaciones')): ?>
        <td class="td-chk"><input type="checkbox" class="row-chk" aria-label="Seleccionar fila" value="<?= $c['id'] ?>"></td>
        <?php endif; ?>
        <td>
          <div class="av-row">
            <span class="av-chip" data-name="<?= sanitize($c['razon_social']) ?>"></span>
            <div class="av-name">
              <a class="row-link" href="ver.php?id=<?= $c['id'] ?>"><span class="txt-ellipsis" title="<?= sanitize($c['razon_social']) ?>"><?= sanitize($c['razon_social']) ?></span></a>
              <div style="font-size:11px;color:var(--gris-texto);"><?= ggi_copy($c['ruc']) ?></div>
            </div>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
            <?= ggi_tags([$c['norma_codigo']], ['noun' => 'norma']) ?>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#8B5CF6" stroke-width="2" style="flex-shrink:0;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            <?= sanitize($c['casa_nombre'] ?? '—') ?>
          </div>
        </td>
        <td style="font-family:monospace;font-size:12px;"><?= $c['numero_certificado'] ? ggi_copy($c['numero_certificado']) : '—' ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#6B7280" stroke-width="2" style="flex-shrink:0;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <?= $c['fecha_emision'] ? formato_fecha($c['fecha_emision']) : '—' ?>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#F59E0B" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <div>
              <?= $c['fecha_vencimiento'] ? formato_fecha($c['fecha_vencimiento']) : '—' ?>
              <?php if (!empty($c['fecha_vencimiento']) && $c['estado'] !== 'vencido' && $c['estado'] !== 'suspendido'): ?>
              <div style="margin-top:2px;">
                <span class="dias-chip <?= $dias <= 30 ? 'badge-red' : ($dias <= 90 ? 'badge-amber' : 'badge-green') ?>">
                  <?= $dias > 0 ? "$dias días" : 'Vence hoy' ?>
                </span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td style="text-align:center;">
          <?php /* badge via badge_clase() + estado-sel */ ?>
          <?php if (has_role('gerente_general','gerente_operaciones')): ?>
          <div class="estado-sel"
               data-id="<?= $c['id'] ?>"
               data-value="<?= htmlspecialchars($c['estado']) ?>"
               data-color="<?= htmlspecialchars($estado_color) ?>"
               data-module="certificados"
               role="button" tabindex="0">
            <span class="estado-sel-label"><?= htmlspecialchars($label_estado) ?></span>
            <div class="estado-sel-menu" hidden>
              <?php foreach ($estado_color_map as $ek => $ec): ?>
              <div class="estado-sel-option"
                   data-value="<?= $ek ?>"
                   data-color="<?= $ec ?>"
                   style="--opt-color:<?= $ec ?>">
                <?= htmlspecialchars($label_estado_map[$ek]) ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php else: ?>
          <span class="<?= badge_clase($c['estado']) ?>"><?= htmlspecialchars($label_estado) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <div class="action-btns">
            <a href="<?= APP_URL ?>/modules/certificados/ver.php?id=<?= $c['id'] ?>" class="btn-ic btn-ic-blue" title="Ver detalle">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <?php if ($user['rol'] !== 'gestor_logistico'): ?>
            <a href="<?= APP_URL ?>/modules/certificados/editar.php?id=<?= $c['id'] ?>&from=listado" class="btn-ic btn-ic-blue" title="Editar">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </a>
            <?php endif; ?>
            <?php
              $_cert_renovar = in_array($c['estado'], ['por_vencer','vencido']) && can('propuestas');
              $_cert_del     = has_role('gerente_general','gerente_operaciones');
            ?>
            <?php if ($_cert_renovar || $_cert_del): ?>
            <div class="ggi-row-menu-wrap">
              <button type="button" class="ggi-row-menu-btn" title="Más acciones" aria-label="Más acciones" aria-haspopup="true" aria-expanded="false">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
              </button>
              <div class="ggi-row-menu">
                <?php if ($_cert_renovar): ?>
                <a href="<?= APP_URL ?>/modules/cotizaciones/crear.php?renovar_cert=<?= $c['id'] ?>" class="ggi-row-menu-item">
                  <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.12"/></svg>
                  Iniciar renovación
                </a>
                <?php endif; ?>
                <?php if ($_cert_del): ?>
                <?php if ($_cert_renovar): ?><div class="ggi-row-menu-sep"></div><?php endif; ?>
                <button type="button" class="ggi-row-menu-item danger"
                  data-del-cert="<?= $c['id'] ?>"
                  onclick="ggiEliminarCertificado(<?= $c['id'] ?>)">
                  <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6m4-6v6"/><path d="M9 6V4h6v2"/></svg>
                  Eliminar certificado
                </button>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($total_rows > 0): ?>
    <?= ggi_pager($total_rows, $page, $limit, [
        'per_page_options' => [10, 20, 30],
        'param_page'       => 'page',
        'noun'             => 'certificado',
        'noun_plural'      => 'certificados',
    ]) ?>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function ggiEliminarCertificado(id) {
  const fd = new FormData();
  fd.append('cert_id', id);
  fd.append('_csrf', document.querySelector('[name="_csrf"]')?.value || '');
  ggiEliminar(
    '<?= APP_URL ?>/modules/certificados/ajax_eliminar.php',
    fd,
    function() {
      const btn = document.querySelector('[data-del-cert="' + id + '"]');
      if (btn) btn.closest('tr')?.remove();
    },
    'Eliminar certificado',
    'certificado'
  );
}

ggiReady(() => {
  const params = new URLSearchParams(window.location.search);
  const msg = params.get('msg');
  if (msg && typeof showToast === 'function') {
    showToast(decodeURIComponent(msg), 'ok');
    history.replaceState({}, '', window.location.pathname);
  }

  document.addEventListener('estado-sel-change', async function(e) {
    if (e.detail.module !== 'certificados') return;
    const d = e.detail;
    try {
      const data = await ggiPost('<?= APP_URL ?>/modules/certificados/ajax_estado_cert.php',
                                 { cert_id: d.id, estado: d.value }, { silent: true });
      if (data.ok) {
        d.el.setAttribute('data-color', d.color);
        d.el.setAttribute('data-value', d.value);
        const lbl = d.el.querySelector('.estado-sel-label');
        if (lbl) lbl.textContent = d.label;
        if (typeof window.ggiApplySelectColors === 'function') window.ggiApplySelectColors(d.el.closest('tbody') || document);
        if (typeof showToast==='function') showToast('Estado actualizado.','ok');
      } else {
        if (typeof showToast==='function') showToast(data.msg||'Error.','error');
      }
    } catch(e) { /* connection error */ }
  });

<?php if (has_role('gerente_general','gerente_operaciones')): ?>
  // ── Bulk actions — bar appended to body (outside <main>) to avoid position:fixed containment ──
  const CERT_BULK_URL = '<?= APP_URL ?>/modules/certificados/ajax_bulk.php';

  // Eliminar barra anterior (limpieza SPA)
  const _oldBar = document.getElementById('_bulk-cert');
  if (_oldBar) _oldBar.remove();

  // Crear barra y adjuntar directamente a body
  const _certBar = document.createElement('div');
  _certBar.id = '_bulk-cert';
  _certBar.className = 'ggi-bulk-bar';
  _certBar.innerHTML =
    '<span class="ggi-bulk-count" id="_bulk-cert-cnt">0 seleccionados</span>' +
    '<div class="ggi-bulk-sep"></div>' +
    '<button class="ggi-bulk-btn ggi-bulk-btn-ok"   onclick="bulkCertAccion(\'vigente\')">Vigente</button>' +
    '<button class="ggi-bulk-btn ggi-bulk-btn-warn"  onclick="bulkCertAccion(\'por_vencer\')">Por vencer</button>' +
    '<button class="ggi-bulk-btn ggi-bulk-btn-err"   onclick="bulkCertAccion(\'vencido\')">Vencido</button>' +
    '<div class="ggi-bulk-sep"></div>' +
    '<button class="ggi-bulk-btn-cancel" onclick="bulkCertLimpiar()">Cancelar</button>';
  document.body.appendChild(_certBar);

  // Limpiar barra cuando se navega a otra página
  window.addEventListener('spa:before', function _certBarCleanup() {
    _certBar.remove();
    window.removeEventListener('spa:before', _certBarCleanup);
  });

  function _certGetIds() {
    return [...document.querySelectorAll('.row-chk:checked')].map(c => +c.value).filter(v => v > 0);
  }
  function _certUpdateBar() {
    const ids = _certGetIds();
    const cnt = document.getElementById('_bulk-cert-cnt');
    if (cnt) cnt.textContent = ids.length + ' seleccionado' + (ids.length !== 1 ? 's' : '');
    if (ids.length > 0) _certBar.classList.add('visible');
    else _certBar.classList.remove('visible');
    document.querySelectorAll('.row-chk').forEach(cb => {
      const tr = cb.closest('tr');
      if (tr) tr.classList.toggle('bulk-selected', cb.checked);
    });
    const all = document.getElementById('chk-all-cert');
    if (all) {
      const total = document.querySelectorAll('.row-chk').length;
      const checked = document.querySelectorAll('.row-chk:checked').length;
      all.checked = total > 0 && checked === total;
      all.indeterminate = checked > 0 && checked < total;
    }
  }
  window.bulkCertLimpiar = function() {
    document.querySelectorAll('.row-chk').forEach(c => { c.checked = false; });
    const all = document.getElementById('chk-all-cert');
    if (all) { all.checked = false; all.indeterminate = false; }
    document.querySelectorAll('.bulk-selected').forEach(tr => tr.classList.remove('bulk-selected'));
    _certBar.classList.remove('visible');
  };
  window.bulkCertAccion = async function(nuevoEstado) {
    const ids = _certGetIds();
    if (!ids.length) return;
    const labels  = { vigente:'Marcar vigente', por_vencer:'Marcar por vencer', vencido:'Marcar vencido' };
    const typeMap = { vigente:'info', por_vencer:'warning', vencido:'danger' };
    const ok = await ggiConfirm(
      labels[nuevoEstado] || nuevoEstado,
      `¿${labels[nuevoEstado] || nuevoEstado} los ${ids.length} certificado(s) seleccionados?`,
      { type: typeMap[nuevoEstado] || 'warning', confirmText: labels[nuevoEstado] || 'Confirmar' }
    );
    if (!ok) return;
    try {
      const r = await fetch(CERT_BULK_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: JSON.stringify({ ids, estado: nuevoEstado })
      });
      const d = await r.json();
      if (d.ok) {
        showToast(d.mensaje || `${d.actualizados} certificado(s) actualizados.`, 'ok');
        setTimeout(() => location.reload(), 800);
      } else { showToast(d.error || 'Error al procesar.', 'error'); }
    } catch(e) { showToast('Error de conexión.', 'error'); }
  };
  document.querySelectorAll('.row-chk').forEach(chk => {
    chk.addEventListener('change', _certUpdateBar);
  });
  const certAllChk = document.getElementById('chk-all-cert');
  if (certAllChk) {
    certAllChk.addEventListener('change', function() {
      document.querySelectorAll('.row-chk').forEach(c => { c.checked = this.checked; });
      _certUpdateBar();
    });
  }
<?php endif; ?>
});
</script>

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
  /* Ocultar: checkbox, N° cert, emisión en móvil */
  .data-table td:nth-child(1),
  .data-table td:nth-child(5),
  .data-table td:nth-child(6) { display:none; }
  .data-table td:last-child { margin-top:10px; padding-top:10px; border-top:1px solid var(--bg-surface2); }
  .action-btns { gap:8px; }
  .btn-ic { width:36px; height:36px; }
  .toolbar { flex-direction:column; align-items:stretch; }
  .toolbar form { flex-direction:column; flex-wrap:nowrap; }
  .search-wrap { max-width:100%; }
}
</style>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
<script>
if (window.ShortcutManager) {
  ShortcutManager.initListNav({
    getLink: function(row) {
      var a = row.querySelector('a.row-link');
      return a ? a.href : null;
    }
  });
}
</script>
