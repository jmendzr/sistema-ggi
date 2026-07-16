<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$page_title  = 'Detalle Cliente';
$active_menu = 'clientes';


$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/clientes/index.php'); exit; }

$pdo = Database::get();

$cliente = $pdo->prepare("
    SELECT c.*, ej.nombre AS ejecutivo_nombre, ej.apellido AS ejecutivo_apellido
    FROM clientes c
    LEFT JOIN usuarios ej ON ej.id = c.ejecutivo_id
    WHERE c.id = ?
");
$cliente->execute([$id]);
$c = $cliente->fetch();
if (!$c) { header('Location: ' . APP_URL . '/modules/clientes/index.php'); exit; }

$from = $_GET['from'] ?? 'listado';
$back_url = $from === 'dashboard' ? APP_URL . '/modules/dashboard/index.php' : APP_URL . '/modules/clientes/index.php';
$page_title = $c['razon_social'];

// ISOs del cliente
$isos = $pdo->prepare("
    SELECT ci.*, n.codigo, n.nombre FROM cliente_isos ci
    JOIN normas_iso n ON n.id = ci.norma_iso_id
    WHERE ci.cliente_id = ?
");
$isos->execute([$id]);
$isos = $isos->fetchAll();

// Documentos vigentes
$docs = $pdo->prepare("
    SELECT d.*, u.nombre AS subido_por_nombre FROM documentos_cliente d
    JOIN usuarios u ON u.id = d.subido_por
    WHERE d.cliente_id = ? AND d.es_vigente = 1
    ORDER BY d.tipo_documento
");
$docs->execute([$id]);
$docs = $docs->fetchAll();
$docs_por_tipo = array_column($docs, null, 'tipo_documento');

// Certificados
$certs = $pdo->prepare("
    SELECT ce.*, n.codigo AS norma_codigo, cc.nombre AS casa
    FROM certificados ce
    JOIN normas_iso n ON n.id = ce.norma_iso_id
    LEFT JOIN casas_certificadoras cc ON cc.id = ce.casa_certificadora_id
    WHERE ce.cliente_id = ?
    ORDER BY ce.created_at DESC
");
$certs->execute([$id]);
$certs = $certs->fetchAll();

// Cotizaciones del cliente
$cots = $pdo->prepare("
    SELECT id, numero, estado, monto_total, moneda, fecha_emision, created_at,
           (SELECT GROUP_CONCAT(n.codigo ORDER BY n.codigo SEPARATOR ', ')
            FROM cotizacion_normas cn JOIN normas_iso n ON n.id = cn.norma_iso_id
            WHERE cn.cotizacion_id = cotizaciones.id) AS normas_txt
    FROM cotizaciones
    WHERE cliente_id = ?
       OR (empresa_ruc = ? AND tipo_empresa = 'nueva')
    ORDER BY created_at DESC
    LIMIT 20
");
$cots->execute([$id, $c['ruc']]);
$cots = $cots->fetchAll();

// Comunicaciones
$coms = $pdo->prepare("
    SELECT cm.*, u.nombre, u.apellido FROM comunicaciones_cliente cm
    JOIN usuarios u ON u.id = cm.usuario_id
    WHERE cm.cliente_id = ?
    ORDER BY cm.created_at DESC LIMIT 20
");
$coms->execute([$id]);
$coms = $coms->fetchAll();

$tipos_doc = [
    'ficha_ruc'      => 'Ficha RUC',
    'copia_literal'  => 'Copia Literal',
    'acta_alcance'   => 'Acta de Alcance',
    'voucher_inicial'=> 'Voucher Pago Inicial',
    'voucher_final'  => 'Voucher Pago Final',
];

$nuevo   = isset($_GET['nuevo']);
$editado   = isset($_GET['editado']);
$doc_ok    = isset($_GET['doc_ok']);
$doc_error = isset($_GET['doc_error']);
$user = auth_user();
require_once __DIR__ . '/../../templates/layout.php';

?>

<style>
  .cliente-header { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); padding:24px 28px; margin-bottom:20px; display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; }
  .cliente-main h2 { font-family:'Outfit',sans-serif; font-size:20px; font-weight:700; color:var(--azul); }
  .cliente-meta { display:flex; gap:16px; margin-top:8px; flex-wrap:wrap; }
  .meta-item { font-size:12px; color:var(--gris-texto); display:flex; align-items:center; gap:4px; }
  .meta-item strong { color:var(--gris-dark); }
  .cliente-actions { display:flex; gap:8px; flex-shrink:0; }

  .tabs { display:flex; gap:2px; background:#f0f2f8; padding:4px; border-radius:10px; margin-bottom:20px; width:fit-content; }
  .tab { padding:8px 16px; border-radius:7px; font-size:13px; font-weight:500; cursor:pointer; color:var(--gris-texto); border:none; background:none; transition:all 0.15s; font-family:'Plus Jakarta Sans',sans-serif; }
  .tab.active { background:#fff; color:var(--azul); font-weight:600; box-shadow:0 1px 4px rgba(0,0,0,0.08); }
  .tab-panel { display:none; }
  .tab-panel.active { display:block; }

  /* Documentos */
  .docs-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; }
  .doc-card { background:#fff; border:1.5px solid var(--gris-borde); border-radius:10px; padding:16px; transition:border-color 0.15s; }
  .doc-card:hover { border-color:var(--azul); }
  .doc-card.uploaded { border-color:#2DB14044; background:#f0faf2; }
  .doc-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
  .doc-tipo { font-size:12px; font-weight:600; color:var(--azul); }
  .doc-status-ok { width:22px; height:22px; background:var(--verde); border-radius:50%; display:flex; align-items:center; justify-content:center; }
  .doc-status-empty { width:22px; height:22px; background:var(--gris-borde); border-radius:50%; display:flex; align-items:center; justify-content:center; }
  .doc-filename { font-size:11px; color:var(--gris-texto); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:8px; }
  .doc-actions { display:flex; gap:6px; }

  /* Comunicaciones */
  .feed { display:flex; flex-direction:column; gap:0; }
  .feed-item { display:flex; gap:12px; padding:14px 0; border-bottom:1px solid #f0f2f8; }
  .feed-item:last-child { border-bottom:none; }
  .feed-avatar { width:32px; height:32px; border-radius:50%; background:var(--azul); color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; text-transform:uppercase; }
  .feed-body { flex:1; }
  .feed-meta { font-size:11px; color:var(--gris-texto); margin-bottom:4px; }
  .feed-meta strong { color:var(--gris-dark); }
  .feed-msg { font-size:13px; color:var(--gris-dark); line-height:1.5; }
  .feed-tipo { display:inline-flex; padding:2px 8px; border-radius:100px; font-size:10px; font-weight:600; background:var(--azul-light); color:var(--azul); margin-left:6px; text-transform:capitalize; }

  .com-form { background:#f8f9fd; border-radius:10px; padding:16px; margin-bottom:16px; }
  .com-form textarea { width:100%; padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:8px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; resize:vertical; min-height:72px; outline:none; }
  .com-form textarea:focus { border-color:var(--azul); }
  .com-footer { display:flex; align-items:center; justify-content:space-between; margin-top:10px; gap:10px; }
  .com-tipo-sel { padding:7px 10px; border:1.5px solid var(--gris-borde); border-radius:8px; font-size:12px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; }

  /* Alert nuevo */
  .alert-nuevo { background:#e8f7eb; border:1px solid #b7e4c0; border-left:4px solid var(--verde); padding:12px 16px; border-radius:8px; font-size:13px; color:#1a6630; margin-bottom:20px; display:flex; align-items:center; gap:8px; }
  .btn-ic { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; border:none; cursor:pointer; text-decoration:none; transition:all 0.15s; flex-shrink:0; }
  .btn-ic-blue { background:#e8edf8; color:#1A3A8F; } .btn-ic-blue:hover { background:#1A3A8F; color:#fff; }
</style>

<?php if ($nuevo): ?>
<div class="alert-nuevo">
  <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
  Empresa registrada exitosamente con código <strong><?= sanitize($c['ruc']) ?></strong>
</div>
<?php endif; ?>

<?php if ($doc_ok): ?>
<div class="alert-nuevo">
  <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
  Documento subido exitosamente.
</div>
<?php endif; ?>
<?php if ($doc_error): ?>
<div style="background:#fff5f5;border:1px solid #fecaca;border-left:4px solid #ef4444;padding:12px 16px;border-radius:8px;font-size:13px;color:#c0392b;margin-bottom:20px;display:flex;align-items:center;gap:8px;">
  <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  No se encontró el archivo. Puede haber sido eliminado del servidor.
</div>
<?php endif; ?>

<!-- Encabezado del cliente -->
<div class="cliente-header">
  <div class="cliente-main">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
<span style="font-size:13px;color:var(--gris-texto);">RUC: <strong><?= sanitize($c['ruc']) ?></strong></span>
      <?php
        $badge_map = ['activo' => 'badge-green', 'inactivo' => 'badge-gray', 'prospecto' => 'badge-amber'];
        $badge = $badge_map[$c['estado']] ?? 'badge-gray';
      ?>
      <span class="badge <?= $badge ?>" style="display:inline-flex;padding:3px 9px;border-radius:100px;font-size:11px;font-weight:600;"><?= ucfirst($c['estado']) ?></span>
    </div>
    <h2><?= sanitize($c['razon_social']) ?></h2>
    <div class="cliente-meta">
      <div class="meta-item"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/></svg> RUC: <strong><?= sanitize($c['ruc']) ?></strong></div>
      <?php if ($c['direccion']): ?>
      <div class="meta-item"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> <strong><?= sanitize($c['distrito'].', '.$c['departamento']) ?></strong></div>
      <?php endif; ?>
      <?php if ($c['contacto_nombre']): ?>
      <div class="meta-item"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> <strong><?= sanitize($c['contacto_nombre'].' '.$c['contacto_apellido']) ?></strong><?= $c['contacto_cargo'] ? ' — '.sanitize($c['contacto_cargo']) : '' ?></div>
      <?php endif; ?>
      <?php if ($c['contacto_telefono']): ?>
      <div class="meta-item"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.62 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> <strong><?= sanitize($c['contacto_telefono']) ?></strong></div>
      <?php endif; ?>
      <?php if ($c['ejecutivo_nombre']): ?>
      <div class="meta-item" style="color:var(--azul)"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Ejecutivo: <strong><?= sanitize($c['ejecutivo_nombre'].' '.$c['ejecutivo_apellido']) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="cliente-actions">
    <a href="<?= APP_URL ?>/modules/clientes/editar.php?id=<?= $id ?>&from=ficha" class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Editar
    </a>
    <a href="<?= $back_url ?>" class="btn btn-outline btn-sm">← Volver</a>
  </div>
</div>

<!-- Tabs -->
<div class="tabs">
  <button class="tab active" onclick="switchTab('documentos', this)">Documentos</button>
  <button class="tab" onclick="switchTab('certificados', this)">Certificados (<?= count($certs) ?>)</button>
  <button class="tab" onclick="switchTab('comunicaciones', this)">Comunicaciones (<?= count($coms) ?>)</button>
  <button class="tab" onclick="switchTab('cotizaciones', this)">Cotizaciones (<?= count($cots) ?>)</button>
</div>

<!-- Tab: Documentos -->
<div id="tab-documentos" class="tab-panel active">
  <div class="docs-grid">
    <?php foreach ($tipos_doc as $tipo => $label): ?>
    <?php $doc = $docs_por_tipo[$tipo] ?? null; ?>
    <div class="doc-card <?= $doc ? 'uploaded' : '' ?>">
      <div class="doc-header">
        <div class="doc-tipo"><?= $label ?></div>
        <?php if ($doc): ?>
        <div class="doc-status-ok"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>
        <?php else: ?>
        <div class="doc-status-empty"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="var(--gris-texto)" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
        <?php endif; ?>
      </div>
      <?php if ($doc): ?>
        <div class="doc-filename" title="<?= sanitize($doc['nombre_archivo']) ?>">
          <?= sanitize($doc['nombre_archivo']) ?>
          <span style="color:var(--gris-texto);font-size:10px;"> v<?= $doc['version'] ?></span>
        </div>
        <div class="doc-actions">
          <a href="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc['id'] ?>" class="btn btn-outline btn-sm">Ver</a>
          <a href="<?= APP_URL ?>/modules/documentos/subir.php?cliente_id=<?= $id ?>&tipo=<?= $tipo ?>" class="btn btn-outline btn-sm">Actualizar</a>
        </div>
      <?php else: ?>
        <div class="doc-filename" style="color:var(--gris-texto);">Sin documento cargado</div>
        <div class="doc-actions">
          <a href="<?= APP_URL ?>/modules/documentos/subir.php?cliente_id=<?= $id ?>&tipo=<?= $tipo ?>" class="btn btn-primary btn-sm">Subir</a>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Tab: Certificados -->
<div id="tab-certificados" class="tab-panel">
  <?php if (empty($certs)): ?>
  <div class="card" style="text-align:center;padding:40px;color:var(--gris-texto);">
    <p>Sin certificados registrados.</p>
    <a href="<?= APP_URL ?>/modules/certificados/crear.php?cliente_id=<?= $id ?>" class="btn btn-primary" style="margin-top:12px;">Registrar certificado</a>
  </div>
  <?php else: ?>
  <div style="background:#fff;border-radius:12px;border:1px solid var(--gris-borde);overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--gris-borde);">
      <span style="font-size:13px;font-weight:600;color:var(--azul);"><?= count($certs) ?> certificado<?= count($certs)!=1?'s':'' ?></span>
      <a href="<?= APP_URL ?>/modules/certificados/crear.php?cliente_id=<?= $id ?>&from=ficha" class="btn btn-outline btn-sm">+ Nuevo</a>
    </div>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;min-width:560px;">
        <thead><tr>
          <th style="padding:10px 14px;font-size:11px;font-weight:600;color:var(--gris-texto);text-transform:uppercase;background:#fafbfe;border-bottom:1px solid var(--gris-borde);white-space:nowrap;">Norma</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:600;color:var(--gris-texto);text-transform:uppercase;background:#fafbfe;border-bottom:1px solid var(--gris-borde);white-space:nowrap;">Casa certif.</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:600;color:var(--gris-texto);text-transform:uppercase;background:#fafbfe;border-bottom:1px solid var(--gris-borde);white-space:nowrap;">Vencimiento</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:600;color:var(--gris-texto);text-transform:uppercase;background:#fafbfe;border-bottom:1px solid var(--gris-borde);white-space:nowrap;">Estado</th>
          <th style="padding:10px 14px;background:#fafbfe;border-bottom:1px solid var(--gris-borde);"></th>
        </tr></thead>
        <tbody>
          <?php foreach ($certs as $cert): ?>
          <tr>
            <td style="padding:12px 14px;font-size:13px;border-bottom:1px solid #f0f2f8;white-space:nowrap;"><strong style="color:var(--azul);"><?= sanitize($cert['norma_codigo']) ?></strong></td>
            <td style="padding:12px 14px;font-size:13px;border-bottom:1px solid #f0f2f8;white-space:nowrap;"><?= sanitize($cert['casa']) ?></td>
            <td style="padding:12px 14px;font-size:13px;border-bottom:1px solid #f0f2f8;white-space:nowrap;"><?= $cert['fecha_vencimiento'] ? formato_fecha($cert['fecha_vencimiento']) : '—' ?></td>
            <td style="padding:12px 14px;font-size:13px;border-bottom:1px solid #f0f2f8;"><?= badge_estado_certificado($cert['estado']) ?></td>
            <td style="padding:12px 14px;border-bottom:1px solid #f0f2f8;white-space:nowrap;">
              <div style="display:flex;gap:4px;">
                <a href="<?= APP_URL ?>/modules/certificados/ver.php?id=<?= $cert['id'] ?>&from=ficha" class="btn-ic btn-ic-blue" title="Ver detalle">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </a>
                <a href="<?= APP_URL ?>/modules/certificados/editar.php?id=<?= $cert['id'] ?>&from=ficha" class="btn-ic btn-ic-blue" title="Editar">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Tab: Comunicaciones -->
<div id="tab-comunicaciones" class="tab-panel">
  <div class="card">
    <!-- Formulario nueva comunicación -->
    <form method="POST" action="<?= APP_URL ?>/modules/comunicaciones/guardar.php">
      <?= csrf_field() ?>
      <input type="hidden" name="cliente_id" value="<?= $id ?>">
      <input type="hidden" name="redirect" value="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= $id ?>&tab=comunicaciones">
      <div class="com-form">
        <textarea name="mensaje" placeholder="Escribe una nota, seguimiento o comentario sobre este cliente..." required></textarea>
        <div class="com-footer">
          <select name="tipo" class="com-tipo-sel">
            <option value="nota">Nota</option>
            <option value="llamada">Llamada</option>
            <option value="reunion">Reunión</option>
            <option value="seguimiento">Seguimiento</option>
            <option value="alerta">Alerta</option>
          </select>
          <button type="submit" class="btn btn-primary btn-sm">Agregar nota</button>
        </div>
      </div>
    </form>

    <!-- Feed -->
    <?php if (empty($coms)): ?>
    <div style="text-align:center;padding:32px;color:var(--gris-texto);font-size:13px;">Sin comunicaciones aún.</div>
    <?php else: ?>
    <div class="feed">
      <?php foreach ($coms as $com): ?>
      <div class="feed-item">
        <div class="feed-avatar"><?= strtoupper(substr($com['nombre'],0,1).substr($com['apellido'],0,1)) ?></div>
        <div class="feed-body">
          <div class="feed-meta">
            <strong><?= sanitize($com['nombre'].' '.$com['apellido']) ?></strong>
            <span class="feed-tipo"><?= sanitize($com['tipo']) ?></span>
            — <?= date('d/m/Y H:i', strtotime($com['created_at'])) ?>
          </div>
          <div class="feed-msg"><?= nl2br(sanitize($com['mensaje'])) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Tab: Cotizaciones -->
<div id="tab-cotizaciones" class="tab-panel">
  <?php
  $cot_colores = [
    'borrador'  => ['#6B7280','Borrador'],
    'enviada'   => ['#3B82F6','Enviada'],
    'aceptada'  => ['#10B981','Aceptada'],
    'rechazada' => ['#EF4444','Rechazada'],
    'vencida'   => ['#F59E0B','Vencida'],
  ];
  ?>
  <?php if (empty($cots)): ?>
  <div class="card" style="text-align:center;padding:40px;color:var(--gris-texto);">
    <p>Sin cotizaciones registradas para esta empresa.</p>
    <a href="<?= APP_URL ?>/modules/cotizaciones/crear.php" class="btn btn-outline" style="margin-top:12px;">+ Nueva cotización</a>
  </div>
  <?php else: ?>
  <div style="background:#fff;border-radius:12px;border:1px solid var(--gris-borde);overflow:hidden;">
    <div style="padding:14px 16px;border-bottom:1px solid var(--gris-borde);display:flex;align-items:center;justify-content:space-between;">
      <span style="font-size:13px;font-weight:600;color:var(--azul);"><?= count($cots) ?> cotización<?= count($cots)!=1?'es':'' ?></span>
      <a href="<?= APP_URL ?>/modules/cotizaciones/crear.php" class="btn btn-outline btn-sm">+ Nueva</a>
    </div>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;min-width:520px;">
        <thead><tr>
          <th style="padding:10px 14px;font-size:11px;font-weight:600;color:var(--gris-texto);text-transform:uppercase;background:#fafbfe;border-bottom:1px solid var(--gris-borde);white-space:nowrap;">Número</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:600;color:var(--gris-texto);text-transform:uppercase;background:#fafbfe;border-bottom:1px solid var(--gris-borde);white-space:nowrap;">Normas</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:600;color:var(--gris-texto);text-transform:uppercase;background:#fafbfe;border-bottom:1px solid var(--gris-borde);white-space:nowrap;">Monto</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:600;color:var(--gris-texto);text-transform:uppercase;background:#fafbfe;border-bottom:1px solid var(--gris-borde);white-space:nowrap;">Fecha</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:600;color:var(--gris-texto);text-transform:uppercase;background:#fafbfe;border-bottom:1px solid var(--gris-borde);white-space:nowrap;">Estado</th>
          <th style="padding:10px 14px;background:#fafbfe;border-bottom:1px solid var(--gris-borde);"></th>
        </tr></thead>
        <tbody>
          <?php foreach ($cots as $cot):
            [$cc, $cl] = $cot_colores[$cot['estado']] ?? ['#6B7280', $cot['estado']];
          ?>
          <tr>
            <td style="padding:11px 14px;font-size:13px;border-bottom:1px solid #f0f2f8;white-space:nowrap;"><code style="color:var(--azul);font-weight:700;"><?= sanitize($cot['numero']) ?></code></td>
            <td style="padding:11px 14px;font-size:12px;border-bottom:1px solid #f0f2f8;color:var(--gris-texto);"><?= sanitize($cot['normas_txt'] ?? '—') ?></td>
            <td style="padding:11px 14px;font-size:13px;border-bottom:1px solid #f0f2f8;white-space:nowrap;font-weight:600;">
              <?= $cot['moneda'] === 'USD' ? 'USD' : 'S/' ?> <?= number_format($cot['monto_total'], 2) ?>
            </td>
            <td style="padding:11px 14px;font-size:12px;border-bottom:1px solid #f0f2f8;white-space:nowrap;color:var(--gris-texto);">
              <?= date('d/m/Y', strtotime($cot['created_at'])) ?>
            </td>
            <td style="padding:11px 14px;border-bottom:1px solid #f0f2f8;">
              <span style="background:<?= $cc ?>22;color:<?= $cc ?>;border:1px solid <?= $cc ?>55;padding:2px 9px;border-radius:100px;font-size:11px;font-weight:600;"><?= $cl ?></span>
            </td>
            <td style="padding:11px 14px;border-bottom:1px solid #f0f2f8;white-space:nowrap;">
              <a href="<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= $cot['id'] ?>" class="btn-ic btn-ic-blue" title="Ver cotización">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function switchTab(id, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  btn.classList.add('active');
}

// Abrir tab correcto si viene de redirect
const urlParams = new URLSearchParams(window.location.search);
const tab = urlParams.get('tab');
if (tab) {
  const btn = [...document.querySelectorAll('.tab')].find(t => t.getAttribute('onclick').includes(tab));
  if (btn) switchTab(tab, btn);
}
</script>


<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.alert-nuevo').forEach(el => {
    const msg = el.textContent.trim();
    if (msg && typeof showToast === 'function') {
      showToast(msg, 'ok');
      el.remove();
    }
  });
  document.querySelectorAll('[style*="fff5f5"]').forEach(el => {
    const msg = el.textContent.trim();
    if (msg && typeof showToast === 'function') {
      showToast(msg, 'error');
      el.remove();
    }
  });
});
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
<?php if ($nuevo || $editado): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  <?php if ($nuevo): ?>showToast('Empresa registrada exitosamente.', 'ok');<?php endif; ?>
  <?php if ($editado): ?>showToast('Cambios guardados exitosamente.', 'ok');<?php endif; ?>
});
</script>
<?php endif; ?>
