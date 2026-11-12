<?php
/**
 * modules/planilla/index.php — Planilla: pago a trabajadores por período.
 * Configurable por trabajador: régimen (Planilla/RxH), monto y si comisiona.
 * La comisión se jala del módulo de Bonos (bonos del período por created_at).
 * Acceso: gerencia general + Administración y Finanzas.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general');
if (!defined('MODULO_PLANILLA_ACTIVO') || !MODULO_PLANILLA_ACTIVO) {
    http_response_code(403);
    exit('El módulo de Planilla está temporalmente desactivado.');
}
$pdo = Database::get();

$tab = ($_GET['tab'] ?? 'planilla') === 'config' ? 'config' : 'planilla';

// ── Período (YYYY-MM) ────────────────────────────────────────────────────────
$periodo = $_GET['periodo'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) $periodo = date('Y-m');
$meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
[$py, $pm] = array_map('intval', explode('-', $periodo));
$periodo_label = ($meses[$pm] ?? $pm) . ' ' . $py;
$periodo_prev  = date('Y-m', strtotime($periodo . '-01 -1 month'));
$periodo_next  = date('Y-m', strtotime($periodo . '-01 +1 month'));

// ── Auto-configuración: asegura una fila de config por trabajador activo ──────
// Default: Planilla, monto = su salario_mensual, comisiona sólo ejecutivos.
$pdo->exec("INSERT IGNORE INTO planilla_config (usuario_id, regimen, monto, comisiona, incluir)
            SELECT u.id, 'planilla', COALESCE(u.salario_mensual,0),
                   CASE WHEN u.rol='ejecutivo_ventas' THEN 1 ELSE 0 END, 1
              FROM usuarios u WHERE u.activo = 1");

// Config de todos los trabajadores activos (para pestaña Configuración y como mapa)
$cfgRows = $pdo->query(
    "SELECT u.id, NULLIF(TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))),'') AS trabajador,
            u.rol, c.regimen, c.monto, c.comisiona, c.incluir, c.banco, c.num_cuenta, c.cci
       FROM usuarios u JOIN planilla_config c ON c.usuario_id = u.id
      WHERE u.activo = 1 ORDER BY u.nombre, u.apellido"
)->fetchAll(PDO::FETCH_ASSOC);
$cfgMap = [];
foreach ($cfgRows as $c) $cfgMap[$c['id']] = $c;

$rolLabels = $pdo->query("SELECT nombre, label FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
$regLabels = ['planilla' => 'Planilla', 'rxh' => 'RxH'];

// ── Comisión del período por trabajador (del módulo Bonos, por created_at) ────
$comStmt = $pdo->prepare("SELECT ejecutivo_id, SUM(monto) t FROM bonos_ejecutivos
                           WHERE DATE_FORMAT(created_at,'%Y-%m') = ? AND estado = 'pendiente' GROUP BY ejecutivo_id");
$comStmt->execute([$periodo]);
$comisionMap = $comStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Filas de la planilla del período ─────────────────────────────────────────
$st = $pdo->prepare(
    "SELECT p.*, NULLIF(TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))),'') AS trabajador, u.rol,
            pc.banco, pc.num_cuenta, pc.cci
       FROM planilla p JOIN usuarios u ON u.id = p.usuario_id
       LEFT JOIN planilla_config pc ON pc.usuario_id = p.usuario_id
      WHERE p.periodo = ? ORDER BY u.nombre, u.apellido"
);
$st->execute([$periodo]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Enriquecer: comisión en vivo (si el trabajador comisiona) + neto en vivo
$tot = 0; $pagados = 0; $monto_total = 0.0; $monto_pagado = 0.0;
foreach ($rows as &$r) {
    $comisiona = (int)($cfgMap[$r['usuario_id']]['comisiona'] ?? 0);
    $r['_comisiona'] = $comisiona;
    // Pendiente: comisión en vivo (bonos aún no liquidados). Pagado: la que ya liquidó (snapshot).
    if ($r['estado'] === 'pagado') {
        $r['_comision'] = (float)$r['comision'];
    } else {
        $r['_comision'] = $comisiona ? (float)($comisionMap[$r['usuario_id']] ?? 0) : 0.0;
    }
    $r['_neto']      = round((float)$r['sueldo_base'] + $r['_comision'] + (float)$r['bonos'] - (float)$r['descuentos'], 2);
    $tot++; $monto_total += $r['_neto'];
    if ($r['estado'] === 'pagado') { $pagados++; $monto_pagado += $r['_neto']; }
}
unset($r);
$pendientes = $tot - $pagados;

// Trabajadores a incluir (config.incluir=1) que aún no están en el período
$fq = $pdo->prepare("SELECT COUNT(*) FROM planilla_config c
                      WHERE c.incluir = 1
                        AND NOT EXISTS (SELECT 1 FROM planilla p WHERE p.usuario_id = c.usuario_id AND p.periodo = ?)");
$fq->execute([$periodo]);
$faltan = (int)$fq->fetchColumn();

$page_title  = 'Planilla';
$active_menu = 'planilla';
require_once __DIR__ . '/../../templates/layout.php';

$sol = fn($n) => 'S/ ' . number_format((float)$n, 2);
?>
<style>
  .pl-wrap{max-width:1150px}
  .pl-tabs{display:flex;gap:6px;margin-bottom:16px;border-bottom:1px solid var(--brd)}
  .pl-tabs a{padding:9px 16px;font-size:13px;font-weight:700;color:var(--tx2);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}
  .pl-tabs a.on{color:#7C3AED;border-bottom-color:#7C3AED}
  .pl-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:14px}
  .pl-per{display:inline-flex;align-items:center;gap:8px}
  .pl-per .pbtn{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--brd);border-radius:9px;background:var(--bg-surface);color:var(--tx);text-decoration:none;font-weight:700}
  .pl-per input[type=month]{padding:7px 10px;border:1.5px solid var(--brd);border-radius:9px;background:var(--bg-surface);color:var(--tx);font-size:13px;font-family:inherit}
  .pl-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:14px 0}
  .pl-kpi{background:var(--bg-surface);border:1px solid var(--brd);border-radius:12px;padding:14px 16px}
  .pl-kpi .v{font-size:22px;font-weight:800;color:var(--tx);line-height:1.1}
  .pl-kpi .l{font-size:11px;color:var(--tx2);margin-top:3px}
  .pl-kpi.g{border-color:#7C3AED33}.pl-kpi.g .v{color:#7C3AED}
  .pl-tbl{width:100%;border-collapse:collapse;font-size:13px}
  .pl-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--tx2);padding:9px 10px;border-bottom:1px solid var(--brd);white-space:nowrap}
  .pl-tbl th.r,.pl-tbl td.r{text-align:right}
  .pl-tbl td{padding:10px;border-bottom:1px solid var(--brd);vertical-align:middle}
  .pl-tbl tr:hover td{background:var(--bg-hover)}
  .pl-tbl .num{font-variant-numeric:tabular-nums;white-space:nowrap}
  .pl-tbl .neto{font-weight:800;color:var(--tx)}
  .pl-nom{font-weight:700;color:var(--tx)}
  .pl-rol{font-size:11px;color:var(--tx2)}
  .pl-reg{display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px}
  .pl-reg.planilla{color:#1d4ed8;background:#dbeafe}
  .pl-reg.rxh{color:#7c3aed;background:#ede9fe}
  .pl-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px}
  .pl-pill.pend{color:#8a5b00;background:#fff3d6}.pl-pill.pag{color:#15803d;background:#dcfce7}
  .pl-scroll{overflow-x:auto;background:var(--bg-surface);border:1px solid var(--brd);border-radius:12px;padding:6px 12px}
  .pl-act{display:inline-flex;gap:6px}
  .pl-empty{background:var(--bg-surface);border:1px dashed var(--brd);border-radius:12px;padding:40px 20px;text-align:center;color:var(--tx2)}
  .cfg-in{padding:6px 9px;border:1.5px solid var(--brd);border-radius:8px;background:var(--bg-surface);color:var(--tx);font-size:13px;font-family:inherit}
  .cfg-in.monto{width:110px;text-align:right}
  @media(max-width:720px){.pl-kpis{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="pl-wrap">
  <h1 style="margin:0 0 2px">Planilla</h1>
  <div class="pl-tabs">
    <a href="?tab=planilla&periodo=<?= sanitize($periodo) ?>" class="<?= $tab==='planilla'?'on':'' ?>">Planilla del mes</a>
    <a href="?tab=config" class="<?= $tab==='config'?'on':'' ?>">Configuración</a>
  </div>

<?php if ($tab === 'config'): ?>
  <!-- ══ CONFIGURACIÓN POR TRABAJADOR ══ -->
  <p style="color:var(--tx2);font-size:13px;margin:0 0 14px">Define para cada trabajador su <strong>régimen</strong>, <strong>monto</strong> (sueldo u honorario), si <strong>comisiona</strong> (la comisión se jala de Bonos) y si se <strong>incluye</strong> al generar la planilla.</p>
  <div class="pl-scroll">
    <table class="pl-tbl">
      <thead><tr><th>Trabajador</th><th>Régimen</th><th class="r">Monto (S/)</th><th>Banco</th><th>N° Cuenta</th><th>CCI</th><th style="text-align:center">Comisiona</th><th style="text-align:center">Incluir</th></tr></thead>
      <tbody>
      <?php foreach ($cfgRows as $c): ?>
        <tr data-uid="<?= (int)$c['id'] ?>">
          <td><div class="pl-nom"><?= sanitize($c['trabajador'] ?: '—') ?></div><div class="pl-rol"><?= sanitize($rolLabels[$c['rol']] ?? $c['rol']) ?></div></td>
          <td>
            <select class="cfg-in cfg-reg">
              <option value="planilla" <?= $c['regimen']==='planilla'?'selected':'' ?>>Planilla</option>
              <option value="rxh" <?= $c['regimen']==='rxh'?'selected':'' ?>>RxH (honorarios)</option>
            </select>
          </td>
          <td class="r"><input type="number" step="0.01" min="0" class="cfg-in monto cfg-monto" value="<?= number_format((float)$c['monto'],2,'.','') ?>"></td>
          <td><input type="text" class="cfg-in cfg-banco" style="width:120px" maxlength="60" placeholder="Banco" value="<?= sanitize($c['banco'] ?? '') ?>"></td>
          <td><input type="text" class="cfg-in cfg-cuenta" style="width:150px" maxlength="40" placeholder="N° de cuenta" value="<?= sanitize($c['num_cuenta'] ?? '') ?>"></td>
          <td><input type="text" class="cfg-in cfg-cci" style="width:170px" maxlength="40" placeholder="CCI" value="<?= sanitize($c['cci'] ?? '') ?>"></td>
          <td style="text-align:center"><input type="checkbox" class="cfg-comis" <?= $c['comisiona']?'checked':'' ?>></td>
          <td style="text-align:center"><input type="checkbox" class="cfg-incl" <?= $c['incluir']?'checked':'' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="margin-top:16px;display:flex;justify-content:flex-end">
    <button class="btn btn-primary" id="cfg-save" onclick="cfgGuardar()">Guardar configuración</button>
  </div>

<?php else: ?>
  <!-- ══ PLANILLA DEL MES ══ -->
  <div class="pl-head">
    <div><p style="margin:0;color:var(--tx2);font-size:13px">Pago a trabajadores · <strong style="color:var(--tx)"><?= sanitize($periodo_label) ?></strong></p></div>
    <div class="pl-per">
      <a class="pbtn" href="?periodo=<?= $periodo_prev ?>" title="Mes anterior">‹</a>
      <input type="month" value="<?= sanitize($periodo) ?>" onchange="location.href='?periodo='+this.value">
      <a class="pbtn" href="?periodo=<?= $periodo_next ?>" title="Mes siguiente">›</a>
      <?php if ($faltan > 0): ?><button class="btn btn-primary btn-sm" onclick="plGenerar()" style="margin-left:6px">＋ Generar (<?= $faltan ?>)</button><?php endif; ?>
    </div>
  </div>

  <div class="pl-kpis">
    <div class="pl-kpi"><div class="v"><?= $tot ?></div><div class="l">En planilla</div></div>
    <div class="pl-kpi"><div class="v" style="color:#15803d"><?= $pagados ?></div><div class="l">Pagados</div></div>
    <div class="pl-kpi"><div class="v" style="color:#b45309"><?= $pendientes ?></div><div class="l">Pendientes</div></div>
    <div class="pl-kpi g"><div class="v"><?= $sol($monto_total) ?></div><div class="l">Total período (<?= $sol($monto_pagado) ?> pagado)</div></div>
  </div>

  <?php if (!$rows): ?>
    <div class="pl-empty">
      <p style="margin:0 0 12px">No hay planilla generada para <strong><?= sanitize($periodo_label) ?></strong>.</p>
      <?php if ($faltan > 0): ?><button class="btn btn-primary" onclick="plGenerar()">Generar planilla (<?= $faltan ?> trabajador/es)</button>
      <?php else: ?><span>No hay trabajadores marcados para incluir. Revisa <a href="?tab=config">Configuración</a>.</span><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="pl-scroll">
    <table class="pl-tbl">
      <thead><tr>
        <th>Trabajador</th><th>Régimen</th><th class="r">Base</th><th class="r">Comisión</th><th class="r">Bonos</th><th class="r">Desc.</th>
        <th class="r">Neto</th><th>Estado</th><th>Pago</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $rj = htmlspecialchars(json_encode([
          'id'=>$r['id'],'trabajador'=>$r['trabajador'] ?: '—','base'=>$r['sueldo_base'],
          'bonos'=>$r['bonos'],'descuentos'=>$r['descuentos'],'metodo'=>$r['metodo_pago'],
          'notas'=>$r['notas'],'comision'=>$r['_comision'],'comisiona'=>$r['_comisiona'],
          'banco'=>$r['banco'] ?? '','cuenta'=>$r['num_cuenta'] ?? '','cci'=>$r['cci'] ?? '',
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
      ?>
        <tr>
          <td><div class="pl-nom"><?= sanitize($r['trabajador'] ?: '—') ?></div><div class="pl-rol"><?= sanitize($rolLabels[$r['rol']] ?? $r['rol']) ?></div></td>
          <td><span class="pl-reg <?= $r['regimen'] ?>"><?= $regLabels[$r['regimen']] ?? $r['regimen'] ?></span></td>
          <td class="r num"><?= $sol($r['sueldo_base']) ?></td>
          <td class="r num" style="color:#7c3aed"><?= $r['_comisiona'] ? ($r['_comision'] ? '+'.$sol($r['_comision']) : $sol(0)) : '—' ?></td>
          <td class="r num" style="color:#15803d"><?= (float)$r['bonos'] ? '+'.$sol($r['bonos']) : '—' ?></td>
          <td class="r num" style="color:#b91c1c"><?= (float)$r['descuentos'] ? '−'.$sol($r['descuentos']) : '—' ?></td>
          <td class="r num neto"><?= $sol($r['_neto']) ?></td>
          <td><?php if ($r['estado']==='pagado'): ?><span class="pl-pill pag">✓ Pagado</span><?php else: ?><span class="pl-pill pend">Pendiente</span><?php endif; ?></td>
          <td class="num" style="font-size:12px;color:var(--tx2)">
            <?php if ($r['estado']==='pagado'): ?>
              <?= $r['fecha_pago'] ? sanitize(date('d/m/Y', strtotime($r['fecha_pago']))) : '' ?>
              <?php if ($r['metodo_pago']): ?><br><span style="font-size:11px"><?= sanitize($r['metodo_pago']) ?></span><?php endif; ?>
              <?php if (!empty($r['comprobante_path'])): ?> · <a href="<?= APP_URL ?>/modules/planilla/comprobante.php?id=<?= (int)$r['id'] ?>" target="_blank" style="color:#7C3AED">comprobante</a><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="r"><div class="pl-act">
            <button class="btn btn-outline btn-sm" onclick='plEditar(<?= $rj ?>)'>Editar</button>
            <?php if ($r['estado']==='pagado'): ?><button class="btn btn-outline btn-sm" onclick="plRevertir(<?= (int)$r['id'] ?>)" title="Volver a pendiente">↺</button>
            <?php else: ?><button class="btn btn-primary btn-sm" onclick="plPagar(<?= (int)$r['id'] ?>)">Pagar</button><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php endif; ?>
</div>

<!-- Modal editar (planilla del mes) -->
<div id="pl-modal" class="ggi-modal-overlay">
  <div class="ggi-modal-box" style="max-width:470px">
    <div class="ggi-modal-header"><h3 id="pl-m-tit" class="ggi-modal-title">Editar</h3><button class="ggi-modal-close" onclick="plCerrar()" aria-label="Cerrar">✕</button></div>
    <div class="ggi-modal-body">
      <input type="hidden" id="pl-m-id">
      <div id="pl-m-cuenta" style="margin:0 0 14px;padding:10px 12px;background:var(--bg-hover);border:1px solid var(--brd);border-radius:9px;font-size:12px;color:var(--tx2);display:none"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <label style="font-size:12px;font-weight:600;color:var(--tx2)">Sueldo base (S/)
          <input type="number" step="0.01" min="0" id="pl-m-base" class="cfg-in" style="width:100%;margin-top:4px">
        </label>
        <label style="font-size:12px;font-weight:600;color:var(--tx2)">Comisión (auto de Bonos)
          <input type="text" id="pl-m-com" class="cfg-in" style="width:100%;margin-top:4px" disabled>
        </label>
        <label style="font-size:12px;font-weight:600;color:var(--tx2)">Bonos extra (S/)
          <input type="number" step="0.01" min="0" id="pl-m-bonos" class="cfg-in" style="width:100%;margin-top:4px">
        </label>
        <label style="font-size:12px;font-weight:600;color:var(--tx2)">Descuentos (S/)
          <input type="number" step="0.01" min="0" id="pl-m-desc" class="cfg-in" style="width:100%;margin-top:4px">
        </label>
      </div>
      <label style="font-size:12px;font-weight:600;color:var(--tx2);display:block;margin-top:12px">Método de pago
        <input type="text" id="pl-m-metodo" class="cfg-in" placeholder="Transferencia, Yape, Efectivo…" style="width:100%;margin-top:4px">
      </label>
      <label style="font-size:12px;font-weight:600;color:var(--tx2);display:block;margin-top:12px">Notas
        <input type="text" id="pl-m-notas" maxlength="300" class="cfg-in" style="width:100%;margin-top:4px">
      </label>
      <label style="font-size:12px;font-weight:600;color:var(--tx2);display:block;margin-top:12px">Comprobante (imagen o PDF)
        <input type="file" id="pl-m-comp" accept="image/*,application/pdf" style="width:100%;margin-top:4px;font-size:12px">
      </label>
      <div id="pl-m-neto" style="margin-top:14px;font-size:13px;color:var(--tx2)"></div>
    </div>
    <div class="ggi-modal-footer">
      <button class="btn btn-outline" onclick="plCerrar()">Cancelar</button>
      <button class="btn btn-primary" id="pl-m-save" onclick="plGuardar()">Guardar</button>
    </div>
  </div>
</div>

<script>
const PL_AJAX = '<?= APP_URL ?>/modules/planilla/ajax_planilla.php';
const PL_PER  = '<?= sanitize($periodo) ?>';
let _plCom = 0;

// ── Configuración ──
function cfgGuardar(){
  const items = [];
  document.querySelectorAll('tr[data-uid]').forEach(tr => {
    items.push({
      usuario_id: tr.dataset.uid,
      regimen:    tr.querySelector('.cfg-reg').value,
      monto:      tr.querySelector('.cfg-monto').value || '0',
      banco:      tr.querySelector('.cfg-banco').value,
      num_cuenta: tr.querySelector('.cfg-cuenta').value,
      cci:        tr.querySelector('.cfg-cci').value,
      comisiona:  tr.querySelector('.cfg-comis').checked ? 1 : 0,
      incluir:    tr.querySelector('.cfg-incl').checked ? 1 : 0
    });
  });
  const fd = new FormData();
  fd.append('accion','guardar_config');
  fd.append('items', JSON.stringify(items));
  ggiPost(PL_AJAX, fd, { boton: document.getElementById('cfg-save') })
    .then(d => { if (d && d.ok && typeof showToast==='function') showToast('Configuración guardada.', 'ok'); });
}

// ── Planilla del mes ──
function plGenerar(){
  const fd = new FormData(); fd.append('accion','generar'); fd.append('periodo', PL_PER);
  ggiPost(PL_AJAX, fd).then(d => { if (d && d.ok) location.reload(); });
}
function plPagar(id){
  GGIConfirm.show({ icon:'success', title:'Marcar pagado', message:'¿Confirmas que este pago ya se realizó?', confirmText:'Sí, pagado', onConfirm: () => {
    const fd = new FormData(); fd.append('accion','pagar'); fd.append('id', id);
    ggiPost(PL_AJAX, fd).then(d => { if (d && d.ok) location.reload(); });
  }});
}
function plRevertir(id){
  GGIConfirm.show({ icon:'warning', title:'Revertir pago', message:'¿Volver este pago a pendiente?', confirmText:'Sí, revertir', onConfirm: () => {
    const fd = new FormData(); fd.append('accion','revertir'); fd.append('id', id);
    ggiPost(PL_AJAX, fd).then(d => { if (d && d.ok) location.reload(); });
  }});
}
function plEditar(r){
  _plCom = parseFloat(r.comision) || 0;
  document.getElementById('pl-m-id').value = r.id;
  document.getElementById('pl-m-tit').textContent = 'Editar · ' + r.trabajador;
  document.getElementById('pl-m-base').value  = parseFloat(r.base) || 0;
  document.getElementById('pl-m-bonos').value = parseFloat(r.bonos) || 0;
  document.getElementById('pl-m-desc').value  = parseFloat(r.descuentos) || 0;
  document.getElementById('pl-m-com').value   = r.comisiona ? ('S/ ' + _plCom.toFixed(2)) : 'No comisiona';
  const _cta = document.getElementById('pl-m-cuenta');
  if (r.banco || r.cuenta || r.cci) {
    _cta.innerHTML = '🏦 <strong style="color:var(--tx)">' + (r.banco || '—') + '</strong>'
      + (r.cuenta ? ' · Cta: <strong style="color:var(--tx)">' + r.cuenta + '</strong>' : '')
      + (r.cci ? ' · CCI: ' + r.cci : '');
    _cta.style.display = 'block';
  } else {
    _cta.innerHTML = '🏦 Sin cuenta bancaria registrada (config. en Configuración)';
    _cta.style.display = 'block';
  }
  document.getElementById('pl-m-metodo').value = r.metodo || '';
  document.getElementById('pl-m-notas').value  = r.notas || '';
  document.getElementById('pl-m-comp').value   = '';
  plCalc();
  document.getElementById('pl-modal').classList.add('open');
}
function plCalc(){
  const base = parseFloat(document.getElementById('pl-m-base').value)||0;
  const b = parseFloat(document.getElementById('pl-m-bonos').value)||0;
  const d = parseFloat(document.getElementById('pl-m-desc').value)||0;
  const neto = base + _plCom + b - d;
  document.getElementById('pl-m-neto').innerHTML = 'Neto: <strong style="color:var(--tx);font-size:16px">S/ '+neto.toFixed(2)+'</strong> <span style="color:var(--tx2)">(base + comisión S/ '+_plCom.toFixed(2)+' + bonos − desc.)</span>';
}
['pl-m-base','pl-m-bonos','pl-m-desc'].forEach(id => document.getElementById(id) && document.getElementById(id).addEventListener('input', plCalc));
function plCerrar(){ document.getElementById('pl-modal').classList.remove('open'); }
function plGuardar(){
  const fd = new FormData();
  fd.append('accion','guardar');
  fd.append('id', document.getElementById('pl-m-id').value);
  fd.append('sueldo_base', document.getElementById('pl-m-base').value || '0');
  fd.append('bonos', document.getElementById('pl-m-bonos').value || '0');
  fd.append('descuentos', document.getElementById('pl-m-desc').value || '0');
  fd.append('metodo_pago', document.getElementById('pl-m-metodo').value);
  fd.append('notas', document.getElementById('pl-m-notas').value);
  const f = document.getElementById('pl-m-comp').files[0];
  if (f) fd.append('comprobante', f);
  ggiPost(PL_AJAX, fd, { boton: document.getElementById('pl-m-save') }).then(d => { if (d && d.ok) location.reload(); });
}
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
