<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
// Libera el lock de sesión: solo LEE $_SESSION. Sin esto, las pestañas del
// cliente (que se cargan en paralelo) se encolan una tras otra. (Auditoría 2026-07)
session_write_close();

header('Content-Type: application/json; charset=utf-8');
// El contenido de pestaña cambia con cada deploy de UI → no cachear nunca,
// si no el navegador puede seguir mostrando la versión vieja del tab.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id  = (int)($_GET['id']  ?? 0);
$tab = $_GET['tab'] ?? '';
$allowed_tabs = ['pagos','certificados','llamadas','propuestas','historial'];

if (!$id || !in_array($tab, $allowed_tabs, true)) {
    echo json_encode(['html' => '']);
    exit;
}

$pdo  = Database::get();
$user = auth_user();

// Verify client exists
$chk = $pdo->prepare("SELECT id FROM clientes WHERE id=? LIMIT 1");
$chk->execute([$id]);
if (!$chk->fetchColumn()) {
    echo json_encode(['html' => '']);
    exit;
}

ob_start();

// ── PAGOS ───────────────────────────────────────────────────────────────────
if ($tab === 'pagos') {
    try {
        $pagos_stmt = $pdo->prepare("
            SELECT p.*, cot.numero AS cot_numero, cot.incluye_igv AS cot_incluye_igv,
                   u1.nombre AS subido_por_nombre,
                   u2.nombre AS validado_por_nombre
            FROM pagos_cliente p
            JOIN cotizaciones cot ON cot.id = p.cotizacion_id
            LEFT JOIN usuarios u1 ON u1.id = p.subido_por
            LEFT JOIN usuarios u2 ON u2.id = p.validado_por
            WHERE p.cliente_id = ?
              AND p.deleted_at IS NULL
            ORDER BY p.cotizacion_id DESC, FIELD(p.tipo,'inicial','final')
        ");
        $pagos_stmt->execute([$id]);
        $pagos = $pagos_stmt->fetchAll();
    } catch (Exception $e) {
        $pagos = [];
    }

    if (empty($pagos)): ?>
    <div class="card" style="text-align:center;padding:40px;color:var(--gris-texto);">
      <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin:0 auto 12px;display:block;opacity:0.3;"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      <p style="font-size:13px;">No hay pagos registrados. Se generarán automáticamente al aceptar una propuesta.</p>
    </div>
<?php else:
        $pagos_por_cot = [];
        foreach ($pagos as $p) { $pagos_por_cot[$p['cotizacion_id']][] = $p; }
        $cnt_rechazados = count(array_filter($pagos, fn($p) => $p['estado'] === 'rechazado'));
    ?>
    <?php
        $es_gerente = has_role('gerente_general', 'gerente_operaciones');
    ?>
    <?php if ($cnt_rechazados > 0): ?>
    <div class="alert-banner err" style="margin-bottom:16px;">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span style="font-size:13px;"><strong><?= $cnt_rechazados ?> pago<?= $cnt_rechazados > 1 ? 's' : '' ?> rechazado<?= $cnt_rechazados > 1 ? 's' : '' ?>.</strong> Revisa el motivo y sube el voucher correcto.</span>
    </div>
    <?php endif; ?>
    <?php foreach ($pagos_por_cot as $cot_id_g => $grupo): ?>
    <div style="background:var(--bg-surface);border-radius:12px;border:1px solid var(--gris-borde);margin-bottom:16px;overflow:hidden;">
      <div style="padding:12px 18px;border-bottom:1px solid var(--gris-borde);background:var(--bg-surface2);display:flex;align-items:center;gap:8px;">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--gris-texto);"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <span style="font-size:13px;font-weight:600;color:var(--gris-dark);">Propuesta <?= sanitize($grupo[0]['cot_numero']) ?></span>
      </div>
      <?php foreach ($grupo as $pago): ?>
      <?php
        $estado_cfg = match($pago['estado']) {
          'pendiente_voucher'    => ['label'=>'Pendiente voucher',    'bg'=>'var(--s-warn-bg)','color'=>'var(--s-warn-tx)','dot'=>'#f59e0b'],
          'pendiente_validacion' => ['label'=>'Pendiente validación', 'bg'=>'var(--s-info-bg)','color'=>'var(--tx2)','dot'=>'#94a3b8'],
          'validado'             => ['label'=>'Validado',             'bg'=>'var(--s-ok-bg)','color'=>'var(--s-ok-tx)','dot'=>'#22c55e'],
          'rechazado'            => ['label'=>'Rechazado',            'bg'=>'var(--s-err-bg)','color'=>'var(--s-err-tx)','dot'=>'#ef4444'],
          default                => ['label'=>$pago['estado'],        'bg'=>'var(--s-info-bg)','color'=>'var(--tx2)','dot'=>'#94a3b8'],
        };
        $tipo_label  = $pago['tipo'] === 'inicial' ? 'Pago Inicial (50%)' : 'Pago Final (50%)';
        $moneda_sym  = $pago['moneda'] === 'USD' ? 'USD ' : 'S/ ';
        $puede_subir   = in_array($pago['estado'], ['pendiente_voucher','rechazado']);
        $puede_validar = $user['rol'] === 'gerente_general' && $pago['estado'] === 'pendiente_validacion';
      ?>
      <div id="pago-row-<?= $pago['id'] ?>" style="border-bottom:1px solid var(--gris-borde);">
        <div style="padding:12px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
          <div style="flex:1;min-width:220px;">
            <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;">
              <span style="font-size:12.5px;font-weight:600;color:var(--gris-dark);"><?= $tipo_label ?></span>
              <span style="font-size:16px;font-weight:700;color:var(--gris-dark);letter-spacing:-.2px;"><?= $moneda_sym . number_format($pago['monto'],2) ?></span>
            </div>
            <?php if ($pago['fecha_pago_decl']): ?>
            <div style="font-size:11px;color:var(--gris-texto);margin-top:2px;">
              <?= date('d/m/Y', strtotime($pago['fecha_pago_decl'])) ?>
              <?php if ($pago['banco']): ?> · <?= sanitize($pago['banco']) ?><?php endif; ?>
              <?php if ($pago['referencia']): ?> · <?= sanitize($pago['referencia']) ?><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;">
            <span id="pago-badge-<?= $pago['id'] ?>" data-estado="<?= $pago['estado'] ?>" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:100px;font-size:11px;font-weight:600;background:<?= $estado_cfg['bg'] ?>;color:<?= $estado_cfg['color'] ?>;white-space:nowrap;">
              <span class="pago-dot" style="width:6px;height:6px;border-radius:50%;background:<?= $estado_cfg['color'] ?>;display:inline-block;flex-shrink:0;"></span>
              <span class="pago-badge-label"><?= $estado_cfg['label'] ?></span>
            </span>
            <?php if ($puede_subir): ?>
            <div id="voucher-drop-<?= $pago['id'] ?>" class="voucher-drop-zone">
              <label id="lbl-voucher-<?= $pago['id'] ?>" class="btn btn-primary btn-sm" style="cursor:pointer;white-space:nowrap;">
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="pointer-events:none;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <span>Subir voucher</span>
                <input type="file" id="inp-voucher-<?= $pago['id'] ?>" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none;" onchange="subirVoucherDirecto(<?= $pago['id'] ?>, this)">
              </label>
              <span class="voucher-drop-hint">o arrastra aquí</span>
            </div>
            <?php endif; ?>
            <?php if ($pago['voucher_path'] && in_array($pago['estado'], ['pendiente_validacion','validado'])): ?>
            <a id="pago-ver-voucher-<?= $pago['id'] ?>" href="<?= APP_URL ?>/modules/pagos/ver_voucher.php?id=<?= $pago['id'] ?>" target="_blank" data-ggi-preview data-title="Voucher de pago" class="btn btn-outline btn-sm" style="white-space:nowrap;">
              <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              Ver
            </a>
            <?php endif; ?>
            <?php if ($puede_validar): ?>
            <div id="pago-acciones-<?= $pago['id'] ?>" style="display:flex;gap:6px;">
              <button type="button" class="btn btn-sm" style="background:var(--s-ok);color:#fff;border:none;white-space:nowrap;" onclick="validarPago(<?= $pago['id'] ?>, '<?= $tipo_label ?>')">
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Validar
              </button>
              <button type="button" class="btn btn-sm" style="background:var(--s-err);color:#fff;border:none;white-space:nowrap;" onclick="rechazarPago(<?= $pago['id'] ?>, '<?= $tipo_label ?>')">
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Rechazar
              </button>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($pago['estado'] === 'rechazado' && $pago['notas_validacion']): ?>
        <div id="pago-alert-<?= $pago['id'] ?>" style="padding:10px 18px;background:var(--s-err-bg);border-top:1px solid var(--s-err-brd);display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--s-err);">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span><strong>Pago rechazado.</strong> Motivo: <?= sanitize($pago['notas_validacion']) ?></span>
        </div>
        <?php elseif ($pago['estado'] === 'validado' && $pago['validado_por_nombre']): ?>
        <div id="pago-alert-<?= $pago['id'] ?>" style="padding:10px 18px;background:var(--s-ok-bg);border-top:1px solid var(--s-ok-brd);display:flex;align-items:center;gap:8px;font-size:12px;color:var(--s-ok);">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg>
          Validado por <strong style="margin-left:3px;"><?= sanitize($pago['validado_por_nombre']) ?></strong>&nbsp;·&nbsp;<?= date('d/m/Y', strtotime($pago['fecha_validacion'])) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php $_cot_igv = !empty($grupo[0]['cot_incluye_igv']); ?>
      <?php if (!$_cot_igv): ?>
      <!-- Propuesta sin IGV: no requiere factura -->
      <div style="border-top:1px solid var(--gris-borde);padding:12px 18px;">
        <div style="background:var(--bg-surface2);border:1px solid var(--gris-borde);border-radius:10px;padding:11px 13px;display:flex;align-items:center;gap:8px;color:var(--gris-texto);font-size:12.5px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          <span><strong style="color:var(--gris-dark);">No requiere factura.</strong> Esta propuesta no afecta IGV.</span>
        </div>
      </div>
      <?php else: ?>
      <?php
        // ── Facturas de esta cotización ───────────────────────────────────────
        $fact_stmt = $pdo->prepare("
            SELECT f.*, u.nombre AS subido_nombre, u.apellido AS subido_apellido
            FROM facturas_cliente f
            LEFT JOIN usuarios u ON u.id = f.subido_por
            WHERE f.cliente_id = ? AND f.cotizacion_id = ?
            ORDER BY f.created_at DESC
        ");
        $fact_stmt->execute([$id, $cot_id_g]);
        $cot_facturas = $fact_stmt->fetchAll(\PDO::FETCH_ASSOC);
      ?>
      <?php if ($es_gerente || !empty($cot_facturas)): ?>
      <div style="border-top:1px solid var(--gris-borde);padding:12px 18px;">
        <div style="padding:0;">
          <div style="display:flex;align-items:center;justify-content:space-between;<?= empty($cot_facturas) ? '' : 'margin-bottom:10px;' ?>">
            <span style="font-size:11px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.4px;display:inline-flex;align-items:center;gap:6px;">
              <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
              Facturas
            </span>
            <?php if ($es_gerente && count($cot_facturas) < 2): ?>
            <button type="button" class="btn btn-primary btn-sm"
                    onclick="ggiAbrirSubirFactura(<?= $cot_id_g ?>, '<?= sanitize($grupo[0]['cot_numero']) ?>', 0)">
              <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Cargar factura
            </button>
            <?php endif; ?>
          </div>
          <?php if (empty($cot_facturas)): ?>
          <div style="text-align:center;padding:14px 12px;color:var(--gris-texto);">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4" style="margin:0 auto 6px;display:block;opacity:.4;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p style="font-size:12px;margin:0;">Sin facturas cargadas para esta propuesta.</p>
          </div>
          <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:7px;">
            <?php foreach ($cot_facturas as $_fac): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:var(--bg-surface);border:1px solid var(--gris-borde);border-radius:8px;">
              <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#EF4444" stroke-width="2" style="flex-shrink:0;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <?php $_fac_nombre = sanitize($_fac['archivo_nombre'] ?: ($_fac['numero_factura'] ?? 'Factura')); ?>
              <div style="flex:1;min-width:0;">
                <div style="font-size:12px;font-weight:600;color:var(--gris-dark);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= $_fac_nombre ?>"><?= $_fac_nombre ?></div>
                <div style="font-size:11px;color:var(--gris-texto);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                  <?= date('d/m/Y', strtotime($_fac['created_at'])) ?><?php $_subido = sanitize(trim(($_fac['subido_nombre'] ?? '') . ' ' . ($_fac['subido_apellido'] ?? ''))); if ($_subido): ?> · <?= $_subido ?><?php endif; ?>
                </div>
              </div>
              <div style="display:inline-flex;gap:4px;flex-shrink:0;">
                <a href="<?= APP_URL ?>/<?= sanitize($_fac['archivo_path']) ?>"
                   target="_blank" data-ggi-preview data-title="Factura — <?= sanitize($_fac['archivo_nombre']) ?>" class="btn-ic btn-ic-blue" title="Ver factura">
                  <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </a>
                <?php if ($es_gerente): ?>
                <button type="button" class="btn-ic btn-ic-gray" title="Actualizar PDF"
                        onclick="ggiAbrirSubirFactura(<?= $cot_id_g ?>, '<?= sanitize($grupo[0]['cot_numero']) ?>', <?= $_fac['id'] ?>)">
                  <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </button>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; /* cierra if (!$_cot_igv) / else */ ?>
    </div>
    <?php endforeach; ?>

    <?php if ($es_gerente): ?>
    <!-- Modal cargar factura (compartido — cotizacion_id se inyecta vía JS) -->
    <div id="modal-factura" class="modal-overlay" style="z-index:9999;">
      <div style="background:var(--bg-surface);border-radius:16px;padding:28px 28px 24px;width:100%;max-width:460px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
          <div>
            <h3 style="margin:0 0 2px;font-size:16px;font-weight:700;">Cargar factura</h3>
            <p id="modal-factura-cot-label" style="margin:0;font-size:12px;color:var(--gris-texto);"></p>
          </div>
          <button type="button" onclick="ggiCerrarFactura()" style="background:none;border:none;cursor:pointer;padding:4px;color:var(--gris-texto);">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
        <form id="form-factura">
          <?= csrf_field() ?>
          <input type="hidden" name="cliente_id" value="<?= $id ?>">
          <input type="hidden" name="cotizacion_id" id="modal-factura-cot-id" value="">
          <input type="hidden" name="factura_id"    id="modal-factura-id"     value="">
          <input type="file" name="factura" id="factura-file-input" accept=".pdf,application/pdf" style="display:none;">

          <!-- Zona drag & drop -->
          <div id="factura-drop-zone"
               style="border:2px dashed var(--gris-borde);border-radius:12px;padding:36px 20px;text-align:center;cursor:pointer;transition:all .2s;background:var(--bg-surface2);margin-bottom:16px;"
               onclick="document.getElementById('factura-file-input').click()"
               ondragover="ggiFDragOver(event)"
               ondragleave="ggiFDragLeave(event)"
               ondrop="ggiFDrop(event)">
            <div id="factura-drop-icon">
              <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="#94A3B8" stroke-width="1.4" style="margin:0 auto 10px;display:block;">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="12" y1="12" x2="12" y2="18"/>
                <line x1="9" y1="15" x2="15" y2="15"/>
              </svg>
              <p style="margin:0;font-size:13px;color:#64748B;font-weight:600;">Arrastra el PDF aquí</p>
              <p style="margin:4px 0 0;font-size:12px;color:#94A3B8;">o haz clic para seleccionar · máx. 10 MB</p>
            </div>
            <div id="factura-file-name" style="display:none;align-items:center;justify-content:center;gap:10px;">
              <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="#EF4444" stroke-width="1.8">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
              </svg>
              <span id="factura-name-text" style="font-size:13px;font-weight:600;color:#1E293B;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
              <button type="button" onclick="ggiFClearFile(event)" style="background:none;border:none;cursor:pointer;padding:2px;color:#94A3B8;flex-shrink:0;" title="Quitar archivo">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
          </div>

          <div id="factura-msg" style="display:none;margin-bottom:14px;padding:10px 12px;border-radius:8px;font-size:13px;"></div>

          <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="ggiCerrarFactura()" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="button" id="btn-subir-factura" onclick="ggiSubirFactura()" class="btn btn-primary btn-sm">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
              Cargar factura
            </button>
          </div>
        </form>
      </div>
    </div>
    <script>
    function ggiAbrirSubirFactura(cotizacionId, cotNumero, facturaId) {
      document.getElementById('modal-factura-cot-id').value = cotizacionId;
      document.getElementById('modal-factura-id').value     = facturaId || '';
      const esActualizacion = facturaId > 0;
      document.querySelector('#modal-factura h3').textContent = esActualizacion ? 'Actualizar factura' : 'Cargar factura';
      document.getElementById('modal-factura-cot-label').textContent = 'Propuesta ' + (cotNumero || '#' + cotizacionId);
      document.getElementById('modal-factura').classList.add('open');
    }
    function ggiCerrarFactura() {
      document.getElementById('modal-factura').classList.remove('open');
      document.getElementById('form-factura').reset();
      document.getElementById('modal-factura-id').value = '';
      document.getElementById('factura-msg').style.display = 'none';
      ggiFResetDrop();
    }
    function ggiFResetDrop() {
      document.getElementById('factura-drop-icon').style.display = 'block';
      document.getElementById('factura-file-name').style.display = 'none';
      document.getElementById('factura-drop-zone').style.borderColor = 'var(--gris-borde)';
      document.getElementById('factura-drop-zone').style.background = 'var(--bg-surface2)';
    }
    function ggiFSetFile(file) {
      document.getElementById('factura-drop-icon').style.display = 'none';
      const fn = document.getElementById('factura-file-name');
      fn.style.display = 'flex';
      document.getElementById('factura-name-text').textContent = file.name;
      document.getElementById('factura-drop-zone').style.borderColor = '#EF4444';
      document.getElementById('factura-drop-zone').style.background = 'var(--s-err-bg)';
    }
    function ggiFClearFile(e) {
      e.stopPropagation();
      document.getElementById('factura-file-input').value = '';
      ggiFResetDrop();
    }
    function ggiFDragOver(e) {
      e.preventDefault();
      document.getElementById('factura-drop-zone').style.borderColor = 'var(--azul)';
      document.getElementById('factura-drop-zone').style.background = 'var(--s-info-bg)';
    }
    function ggiFDragLeave(e) {
      document.getElementById('factura-drop-zone').style.borderColor = 'var(--gris-borde)';
      document.getElementById('factura-drop-zone').style.background = 'var(--bg-surface2)';
    }
    function ggiFDrop(e) {
      e.preventDefault();
      const file = e.dataTransfer.files[0];
      if (!file) return;
      if (file.type !== 'application/pdf' && !file.name.endsWith('.pdf')) {
        alert('Solo se permiten archivos PDF.');
        ggiFDragLeave(e);
        return;
      }
      const dt = new DataTransfer();
      dt.items.add(file);
      document.getElementById('factura-file-input').files = dt.files;
      ggiFSetFile(file);
    }
    document.getElementById('factura-file-input').addEventListener('change', function() {
      if (this.files[0]) ggiFSetFile(this.files[0]);
    });
    async function ggiSubirFactura() {
      const fileInput = document.getElementById('factura-file-input');
      if (!fileInput.files[0]) {
        alert('Selecciona o arrastra un archivo PDF.');
        return;
      }
      const btn = document.getElementById('btn-subir-factura');
      const msg = document.getElementById('factura-msg');
      btn.disabled = true;
      btn.textContent = 'Cargando…';
      msg.style.display = 'none';
      try {
        const fd = new FormData(document.getElementById('form-factura'));
        const res = await fetch('<?= APP_URL ?>/modules/facturas/subir.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });
        const data = await res.json();
        if (data.ok) {
          msg.style.cssText = 'display:block;background:var(--s-ok-bg);color:var(--s-ok-tx);border:1px solid var(--s-ok-brd);padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:14px;';
          msg.textContent = '✓ Factura cargada. El cliente recibirá el documento por WhatsApp.';
          // Recargar solo el tab de pagos sin navegar fuera de la página
          setTimeout(function() {
            ggiCerrarFactura();
            var panel = document.getElementById('tab-pagos');
            if (panel) {
              panel.dataset.loaded = '0';
              if (typeof window.switchTab === 'function') window.switchTab('pagos');
            }
          }, 2000);
        } else {
          msg.style.cssText = 'display:block;background:var(--s-err-bg);color:#DC2626;border:1px solid var(--s-err-brd);padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:14px;';
          msg.textContent = data.msg || 'Error al cargar la factura.';
          btn.disabled = false;
          btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg> Cargar factura';
        }
      } catch(err) {
        msg.style.cssText = 'display:block;background:var(--s-err-bg);color:#DC2626;border:1px solid var(--s-err-brd);padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:14px;';
        msg.textContent = 'Error de conexión. Intenta de nuevo.';
        btn.disabled = false;
        btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg> Cargar factura';
      }
    }
    document.getElementById('modal-factura').addEventListener('click', function(e) {
      if (e.target === this) ggiCerrarFactura();
    });
    </script>
    <?php endif; ?>

<?php endif; ?>

<?php
}

// ── CERTIFICADOS ────────────────────────────────────────────────────────────
elseif ($tab === 'certificados') {
    $stmt = $pdo->prepare("
        SELECT ce.*, n.codigo AS norma_codigo, cc.nombre AS casa
        FROM certificados ce
        JOIN normas_iso n ON n.id = ce.norma_iso_id
        LEFT JOIN casas_certificadoras cc ON cc.id = ce.casa_certificadora_id
        WHERE ce.cliente_id = ?
          AND ce.deleted_at IS NULL
        ORDER BY ce.created_at DESC
    ");
    $stmt->execute([$id]);
    $certs = $stmt->fetchAll();
?>
    <?php if (empty($certs)): ?>
    <div class="card" style="text-align:center;padding:40px;color:var(--gris-texto);">
      <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin:0 auto 12px;display:block;opacity:.3;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>
      <p style="font-size:13px;margin:0;">Sin certificados registrados.</p>
      <?php if (can('certificados')): ?>
      <button type="button" class="btn btn-primary" style="margin-top:12px;" onclick="abrirNuevoCert()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Registrar certificado
      </button>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="card" style="padding:4px 20px;margin-top:4px;">
      <?php foreach ($certs as $cert): ?>
      <div class="feed-item" style="align-items:center;gap:14px;">
        <div style="width:38px;height:38px;border-radius:9px;background:var(--ac-soft);color:var(--ac);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13.5px;font-weight:700;color:var(--gris-dark);"><?= sanitize($cert['norma_codigo']) ?></div>
          <div style="font-size:11.5px;color:var(--gris-texto);margin-top:2px;"><?= sanitize($cert['casa'] ?? '—') ?> · Vence: <?= formato_fecha($cert['fecha_vencimiento'] ?? null) ?></div>
        </div>
        <?= badge_estado_certificado($cert['estado'] ?? null) ?>
        <div class="action-btns" style="opacity:1;flex-shrink:0;">
          <a href="<?= APP_URL ?>/modules/certificados/ver.php?id=<?= $cert['id'] ?>&from=ficha" class="btn-ic btn-ic-blue" title="Ver detalle">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </a>
          <a href="<?= APP_URL ?>/modules/certificados/editar.php?id=<?= $cert['id'] ?>&from=ficha" class="btn-ic btn-ic-blue" title="Editar">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php
}

// ── COMUNICACIONES ──────────────────────────────────────────────────────────
elseif ($tab === 'llamadas') {
    // Historial completo de llamadas del cliente (todos los estados).
    $stmt = $pdo->prepare("
        SELECT ll.*, TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS asignado_nombre
        FROM llamadas_programadas ll
        LEFT JOIN usuarios u ON u.id = ll.usuario_id
        WHERE ll.cliente_id = ?
        ORDER BY ll.fecha_hora DESC
    ");
    $stmt->execute([$id]);
    $lls   = $stmt->fetchAll();
    $ahora = time();
?>
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <h3 style="margin:0;font-size:15px;color:var(--tx);">Llamadas agendadas</h3>
        <button type="button" class="btn btn-primary btn-sm" onclick="if(window.ggiAbrirLlamada)window.ggiAbrirLlamada()">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" style="vertical-align:-2px;margin-right:3px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Agendar llamada
        </button>
      </div>
      <?php if (empty($lls)): ?>
        <div style="text-align:center;padding:40px 20px;color:var(--tx3);font-size:13px;">No hay llamadas registradas para este cliente.</div>
      <?php else: ?>
        <?php foreach ($lls as $ll):
          $ts      = strtotime($ll['fecha_hora']);
          $abierta = in_array($ll['estado'], ['pendiente','notificada'], true);
          $vencida = $abierta && $ts < $ahora;
          if     ($ll['estado'] === 'realizada')  { $b_lbl='Realizada';  $b_bg='var(--s-ok-bg)';   $b_tx='var(--s-ok-tx,#059669)'; }
          elseif ($ll['estado'] === 'cancelada')  { $b_lbl='Cancelada';  $b_bg='var(--bg-surface2)'; $b_tx='var(--tx3)'; }
          elseif ($vencida)                        { $b_lbl='Vencida';    $b_bg='var(--s-err-bg)';   $b_tx='var(--s-err)'; }
          else                                     { $b_lbl='Programada'; $b_bg='var(--ac-soft)';    $b_tx='var(--ac)'; }
        ?>
        <div id="llamada-trow-<?= (int)$ll['id'] ?>" style="display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--brd);flex-wrap:wrap;">
          <div style="min-width:120px;">
            <div style="font-size:13px;font-weight:600;color:<?= $vencida ? 'var(--s-err)' : 'var(--tx)' ?>;"><?= date('d/m/Y H:i', $ts) ?></div>
            <div style="font-size:11.5px;color:var(--tx3);">→ <?= sanitize($ll['asignado_nombre'] ?: '—') ?></div>
          </div>
          <span style="font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px;background:<?= $b_bg ?>;color:<?= $b_tx ?>;white-space:nowrap;"><?= $b_lbl ?></span>
          <?php if (!empty($ll['nota'])): ?><span style="font-size:12.5px;color:var(--tx2);flex:1;min-width:140px;"><?= sanitize($ll['nota']) ?></span><?php endif; ?>
          <?php if (!empty($ll['nota_cierre'])): ?><span style="font-size:12px;color:var(--tx3);font-style:italic;min-width:120px;">Cierre: <?= sanitize($ll['nota_cierre']) ?></span><?php endif; ?>
          <?php if ($abierta): ?>
          <span style="margin-left:auto;display:flex;gap:6px;">
            <button type="button" class="btn btn-outline btn-sm" onclick="window.ggiLlamadaAccion(<?= (int)$ll['id'] ?>,'realizar')">Realizada</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="window.ggiLlamadaAccion(<?= (int)$ll['id'] ?>,'cancelar')">Cancelar</button>
          </span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
<?php
}

// ── PROPUESTAS ──────────────────────────────────────────────────────────────
elseif ($tab === 'propuestas') {
    $stmt = $pdo->prepare("
        SELECT cot.numero, cot.estado, cot.monto_total, cot.moneda, cot.fecha_emision,
               cot.id, u.nombre AS creado_por_nombre
        FROM cotizaciones cot
        JOIN usuarios u ON u.id = cot.creado_por
        WHERE cot.cliente_id = ?
          AND cot.deleted_at IS NULL
        ORDER BY cot.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$id]);
    $cots = $stmt->fetchAll();
?>
    <?php if (empty($cots)): ?>
    <div style="text-align:center;padding:36px 12px;color:var(--gris-texto);">
      <svg width="34" height="34" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin:0 auto 12px;display:block;opacity:.3;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
      <p style="font-size:13px;margin:0;">Sin propuestas registradas para este cliente.</p>
    </div>
    <?php else: ?>
    <?php
    // Token de estado (voltea por tema): mantiene el color para diferenciar en claro Y oscuro.
    $cot_estados = [
      'borrador'  => ['neutral','Borrador'],
      'enviada'   => ['info','Enviada'],
      'aceptada'  => ['ok','Aceptada'],
      'rechazada' => ['err','Rechazada'],
      'vencida'   => ['warn','Vencida'],
      'desistida' => ['neutral','Desistida'],
    ];
    ?>
    <div class="card" style="padding:4px 20px;">
      <?php foreach ($cots as $cot):
        [$cc, $cl] = $cot_estados[$cot['estado']] ?? ['neutral', $cot['estado']];
      ?>
      <a href="<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= $cot['id'] ?>" class="feed-item" style="align-items:center;gap:14px;text-decoration:none;">
        <div style="width:38px;height:38px;border-radius:9px;background:var(--ac-soft);color:var(--ac);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:700;color:var(--gris-dark);font-family:monospace;"><?= sanitize($cot['numero']) ?></div>
          <div style="font-size:11.5px;color:var(--gris-texto);margin-top:2px;"><?= $cot['fecha_emision'] ? date('d/m/Y', strtotime($cot['fecha_emision'])) : '—' ?></div>
        </div>
        <div style="font-size:13.5px;font-weight:700;color:var(--gris-dark);white-space:nowrap;"><?= $cot['moneda'] === 'USD' ? 'USD' : 'S/' ?> <?= number_format($cot['monto_total'], 2) ?></div>
        <span style="background:var(--s-<?= $cc ?>-bg);color:var(--s-<?= $cc ?>-tx);border:1px solid var(--s-<?= $cc ?>-bg);padding:2px 9px;border-radius:100px;font-size:11px;font-weight:600;white-space:nowrap;"><?= $cl ?></span>
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--gris-texto)" stroke-width="2" style="flex-shrink:0;"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php
}

// ── HISTORIAL ───────────────────────────────────────────────────────────────
elseif ($tab === 'historial') {
    try {
        $hist_cots = $pdo->prepare("SELECT 'cotizacion' AS tipo, numero AS ref, estado, created_at FROM cotizaciones WHERE cliente_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 20");
        $hist_cots->execute([$id]); $hist_cots = $hist_cots->fetchAll();
    } catch (Exception $e) { $hist_cots = []; }

    try {
        $hist_certs = $pdo->prepare("SELECT 'certificado' AS tipo, n.codigo AS ref, ce.estado, ce.created_at FROM certificados ce JOIN normas_iso n ON n.id=ce.norma_iso_id WHERE ce.cliente_id=? AND ce.deleted_at IS NULL ORDER BY ce.created_at DESC LIMIT 20");
        $hist_certs->execute([$id]); $hist_certs = $hist_certs->fetchAll();
    } catch (Exception $e) { $hist_certs = []; }

    try {
        $hist_envios = $pdo->prepare("SELECT 'envio' AS tipo, e.codigo_envio AS ref, es.nombre AS estado, e.created_at FROM envios e LEFT JOIN estados_envio es ON es.id=e.estado_envio_id WHERE e.cliente_id=? AND e.deleted_at IS NULL ORDER BY e.created_at DESC LIMIT 20");
        $hist_envios->execute([$id]); $hist_envios = $hist_envios->fetchAll();
    } catch (Exception $e) { $hist_envios = []; }

    try {
        $hist_pagos_stmt = $pdo->prepare("
            SELECT 'pago' AS tipo,
                   CONCAT(UPPER(p.tipo), ' · ', cot.numero) AS ref,
                   p.estado, p.created_at, p.monto, p.moneda
            FROM pagos_cliente p
            JOIN cotizaciones cot ON cot.id = p.cotizacion_id
            WHERE p.cliente_id = ? AND p.deleted_at IS NULL
            ORDER BY p.created_at DESC LIMIT 20
        ");
        $hist_pagos_stmt->execute([$id]); $hist_pagos = $hist_pagos_stmt->fetchAll();
    } catch (Exception $e) { $hist_pagos = []; }

    try {
        $hist_aud_stmt = $pdo->prepare("
            SELECT 'auditoria' AS tipo,
                   CONCAT(n.codigo, ' · ', COALESCE(t.label, a.tipo)) AS ref,
                   a.resultado AS estado, a.created_at
            FROM auditorias a
            JOIN certificados ce ON ce.id = a.certificado_id
            JOIN normas_iso n ON n.id = ce.norma_iso_id
            LEFT JOIN (
                SELECT 'seguimiento_1' AS tipo, 'Seguimiento 1' AS label UNION
                SELECT 'seguimiento_2', 'Seguimiento 2' UNION
                SELECT 'renovacion', 'Renovación'
            ) t ON t.tipo = a.tipo
            WHERE ce.cliente_id = ? AND a.deleted_at IS NULL AND ce.deleted_at IS NULL
            ORDER BY a.created_at DESC LIMIT 15
        ");
        $hist_aud_stmt->execute([$id]); $hist_aud = $hist_aud_stmt->fetchAll();
    } catch (Exception $e) { $hist_aud = []; }

    try {
        $hist_resp_stmt = $pdo->prepare("
            SELECT h.*,
                   ua.nombre AS anterior_nombre, ua.apellido AS anterior_apellido,
                   un.nombre AS nuevo_nombre,    un.apellido AS nuevo_apellido,
                   ur.nombre AS regis_nombre,    ur.apellido AS regis_apellido,
                   cot.numero AS cot_numero
            FROM historial_responsables_cliente h
            LEFT JOIN usuarios ua  ON ua.id = h.responsable_anterior
            LEFT JOIN usuarios un  ON un.id = h.responsable_nuevo
            LEFT JOIN usuarios ur  ON ur.id = h.registrado_por
            LEFT JOIN cotizaciones cot ON cot.id = h.cotizacion_id
            WHERE h.cliente_id = ?
            ORDER BY h.created_at DESC LIMIT 50
        ");
        $hist_resp_stmt->execute([$id]); $hist_responsables = $hist_resp_stmt->fetchAll();
    } catch (Exception $e) { $hist_responsables = []; }

    $hist_all = array_merge($hist_cots, $hist_certs, $hist_envios, $hist_pagos, $hist_aud);
    usort($hist_all, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    $hist_all = array_slice($hist_all, 0, 60);

    $_tl_meta = [
        'cotizacion' => ['color'=>'#3b82f6','bg'=>'var(--s-info-bg)','label'=>'Propuesta'],
        'certificado'=> ['color'=>'#10b981','bg'=>'var(--s-ok-bg)','label'=>'Certificado'],
        'envio'      => ['color'=>'#f97316','bg'=>'var(--s-warn-bg)','label'=>'Envío'],
        'pago'       => ['color'=>'#8b5cf6','bg'=>'var(--s-purple-bg)','label'=>'Pago'],
        'auditoria'  => ['color'=>'#0ea5e9','bg'=>'var(--s-info-bg)','label'=>'Auditoría'],
    ];
    $_tl_estado_label = [
        'borrador'             => ['txt'=>'Borrador',       'color'=>'var(--tx2)'],
        'enviada'              => ['txt'=>'Enviada',         'color'=>'#3B82F6'],
        'aceptada'             => ['txt'=>'Aceptada',        'color'=>'#10B981'],
        'rechazada'            => ['txt'=>'Rechazada',       'color'=>'#EF4444'],
        'vencida'              => ['txt'=>'Vencida',         'color'=>'#F59E0B'],
        'desistida'            => ['txt'=>'Desistida',       'color'=>'var(--tx2)'],
        'vigente'              => ['txt'=>'Vigente',         'color'=>'#10B981'],
        'por_vencer'           => ['txt'=>'Por vencer',      'color'=>'#F59E0B'],
        'vencido'              => ['txt'=>'Vencido',         'color'=>'#EF4444'],
        'en_proceso'           => ['txt'=>'En proceso',      'color'=>'#3B82F6'],
        'pendiente_voucher'    => ['txt'=>'Sin voucher',     'color'=>'var(--tx2)'],
        'pendiente_validacion' => ['txt'=>'Por validar',     'color'=>'#F59E0B'],
        'validado'             => ['txt'=>'Validado',        'color'=>'#10B981'],
        'pendiente'            => ['txt'=>'Pendiente',       'color'=>'var(--tx2)'],
        'aprobado'             => ['txt'=>'Aprobado',        'color'=>'#10B981'],
        'con_observaciones'    => ['txt'=>'Con observac.',   'color'=>'#F59E0B'],
        'no_conforme'          => ['txt'=>'No conforme',     'color'=>'#EF4444'],
        'reprogramado'         => ['txt'=>'Reprogramado',    'color'=>'#8B5CF6'],
    ];
?>
<?php if (!empty($hist_responsables)): ?>
<div class="card" style="overflow:hidden;margin-bottom:12px;">
  <div style="padding:12px 16px;border-bottom:1px solid var(--gris-borde);display:flex;align-items:center;gap:8px;">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#1D4ED8" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    <span style="font-size:12px;font-weight:700;color:var(--gris-dark);">Historial de ejecutivo responsable</span>
    <span class="badge-gray" style="margin-left:auto;"><?= count($hist_responsables) ?> cambio<?= count($hist_responsables)!=1?'s':'' ?></span>
  </div>
  <div style="padding:8px 0;">
    <?php foreach ($hist_responsables as $hr): ?>
    <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 16px;border-bottom:1px solid var(--brd2,var(--bg-surface2));">
      <div style="width:32px;height:32px;border-radius:50%;background:<?= $hr['motivo']==='creacion'?'var(--s-ok-bg)':($hr['motivo']==='aceptacion_propuesta'?'var(--s-info-bg)':($hr['motivo']==='reasignacion_manual'?'var(--s-warn-bg)':'var(--bg-surface2)')) ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <?php if ($hr['motivo']==='creacion'): ?>
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#059669" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
        <?php elseif ($hr['motivo']==='aceptacion_propuesta'): ?>
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#1D4ED8" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?php elseif ($hr['motivo']==='reasignacion_manual'): ?>
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#D97706" stroke-width="2"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
        <?php else: ?>
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#6B7280" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:12px;font-weight:600;color:var(--gris-dark);">
          <?php if ($hr['motivo']==='creacion'): ?>
            Empresa registrada — primer responsable asignado
          <?php elseif ($hr['motivo']==='aceptacion_propuesta'): ?>
            Responsabilidad transferida al aceptar propuesta<?= $hr['cot_numero'] ? ' '.$hr['cot_numero'] : '' ?>
          <?php elseif ($hr['motivo']==='reasignacion_manual'): ?>
            Reasignación manual
          <?php else: ?>
            Asignación (migración histórica)
          <?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--gris-texto);margin-top:3px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
          <?php if ($hr['anterior_nombre']): ?>
            <span><?= sanitize($hr['anterior_nombre'].' '.$hr['anterior_apellido']) ?></span>
            <span>→</span>
          <?php endif; ?>
          <?php if ($hr['nuevo_nombre']): ?>
            <span style="font-weight:600;color:var(--gris-dark);"><?= sanitize($hr['nuevo_nombre'].' '.$hr['nuevo_apellido']) ?></span>
          <?php endif; ?>
          <?php if ($hr['regis_nombre'] && $hr['registrado_por'] !== $hr['responsable_nuevo']): ?>
            <span style="color:var(--gris-texto);">· por <?= sanitize($hr['regis_nombre'].' '.$hr['regis_apellido']) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($hr['notas'] && $hr['motivo'] !== 'migracion'): ?>
          <div style="font-size:11px;color:var(--gris-texto);margin-top:2px;font-style:italic;"><?= htmlspecialchars($hr['notas']) ?></div>
        <?php endif; ?>
      </div>
      <div style="font-size:11px;color:var(--gris-texto);white-space:nowrap;flex-shrink:0;"><?= formato_fecha($hr['created_at']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card" style="overflow:hidden;">
  <div style="padding:14px 16px;border-bottom:1px solid var(--gris-borde);display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <span style="font-size:13px;font-weight:600;color:var(--gris-dark);"><?= count($hist_all) ?> evento<?= count($hist_all) != 1 ? 's' : '' ?> en el historial</span>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
      <?php foreach ($_tl_meta as $_tipo => $_tm): ?>
      <span style="display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:600;color:<?= $_tm['color'] ?>;background:<?= $_tm['bg'] ?>;padding:2px 7px;border-radius:5px;"><?= $_tm['label'] ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (empty($hist_all)): ?>
  <div style="text-align:center;padding:40px 12px;color:var(--gris-texto);">
    <svg width="34" height="34" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin:0 auto 12px;display:block;opacity:.3;"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
    <p style="font-size:13px;margin:0;">Sin actividad registrada.</p>
  </div>
  <?php else: ?>
  <div style="padding:20px 20px 4px;" class="timeline-wrap">
    <?php
    $prev_month = '';
    foreach ($hist_all as $_ev):
      $tipo        = $_ev['tipo'];
      $tm          = $_tl_meta[$tipo] ?? ['color'=>'var(--tx2)','bg'=>'var(--s-info-bg)','label'=>ucfirst($tipo)];
      $estado_info = $_tl_estado_label[$_ev['estado'] ?? ''] ?? null;
      $ts          = strtotime($_ev['created_at']);
      $mes_label   = date('F Y', $ts);
      $mes_es      = ['January'=>'Enero','February'=>'Febrero','March'=>'Marzo','April'=>'Abril','May'=>'Mayo','June'=>'Junio','July'=>'Julio','August'=>'Agosto','September'=>'Septiembre','October'=>'Octubre','November'=>'Noviembre','December'=>'Diciembre'];
      $mes_label   = str_replace(array_keys($mes_es), array_values($mes_es), $mes_label);
      if ($mes_label !== $prev_month): $prev_month = $mes_label; ?>
    <div class="timeline-month-label"><?= $mes_label ?></div>
    <?php endif; ?>
    <div class="timeline-item">
      <div class="timeline-left">
        <div class="timeline-dot" style="background:<?= $tm['bg'] ?>;color:<?= $tm['color'] ?>;">
          <?php
          $icons = [
            'cotizacion' => '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
            'certificado'=> '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>',
            'envio'      => '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.63 3.36 2 2 0 0 1 3.6 1.18h3a2 2 0 0 1 2 1.72 12.1 12.1 0 0 0 .66 2.67 2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.91-.91a2 2 0 0 1 2.11-.45 12.1 12.1 0 0 0 2.67.66 2 2 0 0 1 1.72 2.02z"/></svg>',
            'pago'       => '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
            'auditoria'  => '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
          ];
          echo $icons[$tipo] ?? '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
          ?>
        </div>
        <div class="timeline-line"></div>
      </div>
      <div class="timeline-body">
        <div class="timeline-card">
          <div class="timeline-header">
            <span class="timeline-badge" style="background:<?= $tm['bg'] ?>;color:<?= $tm['color'] ?>;"><?= $tm['label'] ?></span>
            <span class="timeline-ref" title="<?= sanitize($_ev['ref'] ?? '') ?>"><?= sanitize($_ev['ref'] ?? '—') ?></span>
            <?php if ($estado_info): ?>
            <span class="timeline-estado" style="color:<?= $estado_info['color'] ?>;">· <?= $estado_info['txt'] ?></span>
            <?php endif; ?>
          </div>
          <div class="timeline-date">
            <?= date('d/m/Y H:i', $ts) ?>
            <?php if ($tipo === 'pago' && isset($_ev['monto'])): ?>
            · <strong><?= $_ev['moneda'] === 'USD' ? 'USD' : 'S/' ?> <?= number_format($_ev['monto'], 2) ?></strong>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php
}

$html = ob_get_clean();
echo json_encode(['html' => $html], JSON_UNESCAPED_UNICODE);
