<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general', 'gerente_operaciones', 'administracion_finanzas');

$pdo = Database::get();

$ejecutivos = $pdo->query("
    SELECT id, nombre, apellido FROM usuarios
    WHERE rol IN ('ejecutivo_ventas','gerente_comercial') AND activo = 1
    ORDER BY nombre, apellido
")->fetchAll();

$filtro_ej  = (int)($_GET['ejecutivo_id'] ?? 0);

// El informe interactivo está BLOQUEADO al mes en curso: no se re-generan meses
// pasados a cada rato. Los meses anteriores se consultan/re-descargan desde el
// Historial (más abajo).
$filtro_año     = (int)date('Y');
$filtro_mes     = (int)date('n');
$filtro_periodo = sprintf('%04d-%02d', $filtro_año, $filtro_mes);

$preview = null;

if ($filtro_ej) {
    // Datos del ejecutivo (sin salario en la respuesta si el ejecutivo consulta — pero esta ruta ya requiere gerente)
    $ej = $pdo->prepare("SELECT id, nombre, apellido, salario_mensual FROM usuarios WHERE id=? AND rol IN ('ejecutivo_ventas','gerente_comercial') AND activo=1 LIMIT 1");
    $ej->execute([$filtro_ej]);
    $ej = $ej->fetch();

    if ($ej) {
        // Bonos pendientes del mes seleccionado
        $periodo_ini = sprintf('%04d-%02d-01 00:00:00', $filtro_año, $filtro_mes);
        $periodo_fin = date('Y-m-t 23:59:59', mktime(0, 0, 0, $filtro_mes, 1, $filtro_año));
        $stmt = $pdo->prepare("
            SELECT be.id, be.monto, be.cotizacion_id, n.codigo AS norma_codigo,
                   cot.numero AS cot_numero, c.razon_social, be.created_at
            FROM bonos_ejecutivos be
            JOIN cotizaciones cot ON cot.id = be.cotizacion_id
            JOIN clientes c       ON c.id   = cot.cliente_id
            JOIN normas_iso n     ON n.id   = be.norma_iso_id
            WHERE be.ejecutivo_id = ?
              AND be.created_at BETWEEN ? AND ?
            ORDER BY be.created_at ASC
        ");
        $stmt->execute([$filtro_ej, $periodo_ini, $periodo_fin]);
        $bonos = $stmt->fetchAll();

        $total_comisiones  = array_sum(array_column($bonos, 'monto'));
        $ventas_cerradas   = count(array_unique(array_column($bonos, 'cotizacion_id')));
        $certs_cerrados    = count($bonos);

        $montos_unicos = array_unique(array_map(fn($b) => (float)$b['monto'], $bonos));
        $comision_unit = count($montos_unicos) === 1 ? reset($montos_unicos) : null;

        $preview = compact('ej', 'bonos', 'total_comisiones', 'ventas_cerradas', 'certs_cerrados', 'comision_unit');
    }
}

$meses_es = ['', 'Enero','Febrero','Marzo','Abril','Mayo','Junio',
              'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

// Historial de informes generados (para re-descargar el PDF)
$historial = [];
try {
    $historial = $pdo->query("
        SELECT bi.*,
               NULLIF(TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))),'') AS ejecutivo,
               NULLIF(TRIM(CONCAT(COALESCE(g.nombre,''),' ',COALESCE(g.apellido,''))),'') AS generado_por_nom
        FROM bonos_informes bi
        JOIN usuarios u ON u.id = bi.ejecutivo_id
        LEFT JOIN usuarios g ON g.id = bi.generado_por
        ORDER BY bi.generado_at DESC LIMIT 50
    ")->fetchAll();
} catch (\Throwable $e) { $historial = []; }

$page_title  = 'Informe de Pago';
$active_menu = 'bonos';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .informe-wrap { max-width: 860px; }
  .page-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:24px; }
  .page-header h1 { font-family:'Inter',sans-serif; font-size:22px; font-weight:700; color:var(--gris-dark); margin-bottom:2px; }
  .page-header p { font-size:13px; color:var(--gris-texto); }

  .card { background:var(--gris-card); border-radius:14px; border:1px solid var(--gris-borde); overflow:hidden; margin-bottom:20px; }
  .card-body { padding:22px; }
  .card-header { padding:16px 22px; border-bottom:1px solid var(--gris-borde); display:flex; align-items:center; gap:12px; }
  .card-header-icon { width:36px; height:36px; border-radius:9px; background:var(--azul-light); color:var(--azul); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .card-header-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:700; color:var(--azul); }
  .card-header-sub { font-size:12px; color:var(--gris-texto); margin-top:1px; }

  .filters-grid { display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end; }
  .filter-field { display:flex; flex-direction:column; gap:6px; }
  .filter-field label { font-size:11px; font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.5px; }
  .filter-field select, .filter-field input[type=number] {
    padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:inherit; background:var(--gris-card); color:var(--gris-dark);
    outline:none; height:40px;
  }
  .filter-field select:focus, .filter-field input:focus { border-color:var(--azul); box-shadow:0 0 0 3px rgba(26,58,143,.08); }
  .filter-field select { min-width:200px; }
  /* Período: input nativo month */
  .filter-field input[type=month] {
    padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:inherit; background:var(--gris-card); color:var(--gris-dark);
    outline:none; height:40px; width:180px; cursor:pointer;
  }
  .filter-field input[type=month]:focus { border-color:var(--azul); box-shadow:0 0 0 3px rgba(26,58,143,.08); }
  /* .btn / .btn-primary / .btn-outline → globales. (.btn-green específico del módulo) */
  .btn-green { background:#059669; color:#fff; }
  .btn-green:hover { opacity:.88; }

  /* Preview table */
  .preview-table { width:100%; border-collapse:collapse; font-size:13px; }
  .preview-table th { background:#1e7a4f; color:#fff; font-weight:700; padding:10px 14px; text-align:left; }
  .preview-table th:last-child { text-align:right; }
  .preview-table td { padding:9px 14px; border-bottom:1px solid var(--gris-borde); vertical-align:middle; }
  .preview-table td:nth-child(2) { text-align:center; color:var(--gris-texto); }
  .preview-table td:last-child { text-align:right; }
  .preview-table tr:last-child td { border-bottom:none; }
  .preview-table .total-row td { background:var(--s-info-bg); font-weight:700; font-size:14px; border-top:2px solid #4db8d8; }
  .preview-table .muted { color:var(--gris-texto); }

  /* Detalle bonos */
  .bonos-detalle { margin-top:16px; }
  .bonos-detalle summary { cursor:pointer; font-size:12px; color:var(--gris-texto); font-weight:600; padding:6px 0; user-select:none; }
  .bonos-detalle summary:hover { color:var(--azul); }
  .det-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:8px; }
  .det-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--gris-texto); padding:6px 10px; background:var(--gris-bg); border-bottom:1px solid var(--gris-borde); text-align:left; }
  .det-table td { padding:6px 10px; border-bottom:1px solid var(--gris-borde); color:var(--gris-dark); }
  .det-table tr:last-child td { border-bottom:none; }

  /* Empty state */
  .empty-preview { text-align:center; padding:48px 24px; color:var(--gris-texto); }
  .empty-preview svg { display:block; margin:0 auto 12px; opacity:.35; }
  .empty-preview p { font-size:13px; }

  :root[data-tema="oscuro"] .preview-table .total-row td { background:#1a3a45 !important; color:#e0f7ff !important; border-color:#2a6a80 !important; }
  :root[data-tema="oscuro"] .preview-table th { background:#155235 !important; }
</style>

<div class="informe-wrap">

  <div class="page-header">
    <div>
      <h1>Informe de Pago</h1>
      <p>Genera el informe mensual de remuneración por ejecutivo</p>
    </div>
    <a href="<?= APP_URL ?>/modules/bonos/index.php" class="btn btn-outline">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
      Ver bonos
    </a>
  </div>

  <!-- Filtros -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-icon">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </div>
      <div>
        <div class="card-header-title">Parámetros del informe</div>
        <div class="card-header-sub">Selecciona el ejecutivo y el período de liquidación</div>
      </div>
    </div>
    <div class="card-body">
      <form method="GET" action="">
        <div class="filters-grid">
          <div class="filter-field">
            <label>Colaborador</label>
            <select name="ejecutivo_id" required>
              <option value="">— Seleccionar ejecutivo —</option>
              <?php foreach ($ejecutivos as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= $filtro_ej == $e['id'] ? 'selected' : '' ?>>
                <?= sanitize($e['nombre'] . ' ' . $e['apellido']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-field">
            <label>Período</label>
            <div style="height:40px;display:inline-flex;align-items:center;gap:7px;padding:0 13px;border:1.5px solid var(--gris-borde);border-radius:9px;background:var(--gris-bg,#f1f5f9);font-size:13px;font-weight:700;color:var(--gris-dark);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <?= $meses_es[$filtro_mes] . ' ' . $filtro_año ?>
            </div>
          </div>
          <div class="filter-field">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-primary">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Generar informe
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Preview -->
  <?php if ($filtro_ej && !$preview): ?>
  <div class="card">
    <div class="card-body empty-preview">
      <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
      <p>Ejecutivo no encontrado o sin acceso.</p>
    </div>
  </div>

  <?php elseif ($preview): ?>
  <?php
    $nombre_mes = $meses_es[$filtro_mes];
    $p = $preview;
    $salario = (float)$p['ej']['salario_mensual'];
    $total_pagar = $salario + (float)$p['total_comisiones'];
  ?>
  <div class="card">
    <div class="card-header">
      <div class="card-header-icon" style="background:var(--s-ok-bg);">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#059669" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
      </div>
      <div style="flex:1;">
        <div class="card-header-title">
          Informe <?= $nombre_mes ?> <?= $filtro_año ?> — <?= sanitize($p['ej']['nombre'] . ' ' . $p['ej']['apellido']) ?>
        </div>
        <div class="card-header-sub">
          <?= $p['certs_cerrados'] ?> comisión(es) del mes · <?= $p['ventas_cerradas'] ?> venta(s)
        </div>
      </div>
      <?php if (!empty($p['bonos'])): ?>
      <a href="<?= APP_URL ?>/modules/bonos/generar_informe_pdf.php?ejecutivo_id=<?= $filtro_ej ?>&month=<?= $filtro_mes ?>&year=<?= $filtro_año ?>"
         target="_blank" class="btn btn-green" style="flex-shrink:0;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Descargar PDF
      </a>
      <?php endif; ?>
    </div>

    <div class="card-body">
      <?php if (empty($p['bonos'])): ?>
      <div class="empty-preview" style="padding:32px;">
        <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        <p>No hay comisiones para <?= $nombre_mes ?> <?= $filtro_año ?>.</p>
      </div>
      <?php else: ?>

      <table class="preview-table">
        <thead>
          <tr>
            <th scope="col">Concepto</th>
            <th scope="col" style="text-align:center;">Detalle</th>
            <th scope="col" style="text-align:right;">Monto (S/.)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Monto acordado mensual</td>
            <td class="muted">—</td>
            <td>S/ <?= number_format($salario, 2) ?></td>
          </tr>
          <tr>
            <td>Ventas cerradas</td>
            <td><?= $p['ventas_cerradas'] ?></td>
            <td class="muted">—</td>
          </tr>
          <tr>
            <td>Certificados cerrados</td>
            <td><?= $p['certs_cerrados'] ?></td>
            <td class="muted">—</td>
          </tr>
          <?php if ($p['comision_unit'] !== null): ?>
          <tr>
            <td>Comisión por certificado</td>
            <td class="muted">—</td>
            <td>S/ <?= number_format($p['comision_unit'], 2) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td>Total comisiones</td>
            <td class="muted">—</td>
            <td>S/ <?= number_format($p['total_comisiones'], 2) ?></td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td colspan="2"><strong>TOTAL A PAGAR</strong></td>
            <td><strong>S/ <?= number_format($total_pagar, 2) ?></strong></td>
          </tr>
        </tfoot>
      </table>

      <!-- Detalle de bonos expandible -->
      <details class="bonos-detalle">
        <summary>Ver detalle de comisiones (<?= $p['certs_cerrados'] ?>)</summary>
        <table class="det-table">
          <thead>
            <tr>
              <th scope="col">#</th>
              <th scope="col">Cotización</th>
              <th scope="col">Cliente</th>
              <th scope="col">Norma</th>
              <th scope="col">Monto</th>
              <th scope="col">Fecha</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($p['bonos'] as $i => $b): ?>
            <tr>
              <td style="color:var(--gris-texto);"><?= $i + 1 ?></td>
              <td><?= sanitize($b['cot_numero']) ?></td>
              <td class="txt-ellipsis" style="max-width:180px;" title="<?= sanitize($b['razon_social']) ?>"><?= sanitize($b['razon_social']) ?></td>
              <td><span style="font-size:10px;font-weight:700;background:var(--azul-light);color:var(--azul);padding:1px 6px;border-radius:5px;"><?= sanitize($b['norma_codigo']) ?></span></td>
              <td style="font-weight:600;">S/ <?= number_format((float)$b['monto'], 2) ?></td>
              <td style="color:var(--gris-texto);"><?= date('d/m/Y', strtotime($b['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </details>

      <?php endif; ?>
    </div>
  </div>

  <?php elseif ($filtro_ej === 0): ?>
  <div class="card">
    <div class="card-body empty-preview">
      <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
      <p>Selecciona un ejecutivo y un período para generar el informe.</p>
    </div>
  </div>
  <?php endif; ?>

  <!-- Historial de informes generados -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-icon">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
      </div>
      <div>
        <div class="card-header-title">Historial de informes</div>
        <div class="card-header-sub">Informes generados · re-descarga el PDF cuando quieras (incluye meses anteriores)</div>
      </div>
    </div>
    <div class="card-body" style="padding:0;">
      <?php if (!$historial): ?>
        <div class="empty-preview" style="padding:28px;">Aún no se han generado informes.</div>
      <?php else: ?>
      <table class="preview-table" style="font-size:12.5px;">
        <thead><tr>
          <th scope="col">Ejecutivo</th>
          <th scope="col" style="text-align:center;">Período</th>
          <th scope="col" style="text-align:right;">Total</th>
          <th scope="col">Generado</th>
          <th scope="col" style="text-align:right;"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($historial as $hh):
          $_hp = explode('-', $hh['periodo']); $hy = (int)($_hp[0] ?? 0); $hm = (int)($_hp[1] ?? 0);
        ?>
          <tr>
            <td><?= sanitize($hh['ejecutivo'] ?: '—') ?></td>
            <td style="text-align:center;"><?= ($meses_es[$hm] ?? $hm) . ' ' . $hy ?></td>
            <td style="text-align:right;font-weight:700;">S/ <?= number_format((float)$hh['total'], 2) ?></td>
            <td style="color:var(--gris-texto);font-size:11.5px;">
              <?= sanitize(date('d/m/Y H:i', strtotime($hh['generado_at']))) ?><?php if ($hh['generado_por_nom']): ?> · <?= sanitize($hh['generado_por_nom']) ?><?php endif; ?><?php if ((int)$hh['veces'] > 1): ?> · <?= (int)$hh['veces'] ?>×<?php endif; ?>
            </td>
            <td style="text-align:right;">
              <a href="<?= APP_URL ?>/modules/bonos/generar_informe_pdf.php?ejecutivo_id=<?= (int)$hh['ejecutivo_id'] ?>&month=<?= $hm ?>&year=<?= $hy ?>" target="_blank" class="btn btn-outline btn-sm">
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                PDF
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
